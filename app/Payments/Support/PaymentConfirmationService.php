<?php

namespace App\Payments\Support;

use App\Core\Notifications\CustomerNotifier;
use App\Models\Payment;
use App\Models\TrainingAccess;
use App\Models\User;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Events\PaymentConfirmed;
use App\Training\Enums\TrainingAccessStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El ÚNICO camino que escribe en TrainingAccess a partir de un Payment
 * (Hito 8) — ni PaymentHandler ni ningún Filament Action tocan TrainingAccess
 * directamente. TrainingAccessGate/TrainingEngine no cambian en absoluto.
 *
 * $reviewer es nullable a propósito: hoy siempre es un humano (`is_super_admin`,
 * vía App\Filament\Resources\Payments), pero el mismo método debe poder
 * invocarse en el futuro desde un webhook de pasarela real (Hito futuro,
 * NO implementado aquí) sin cambiar esta firma — ahí la pasarela misma es
 * la autoridad, sin revisión humana. Ver docs/DECISIONS.md.
 *
 * Idempotencia (ajuste de Hito 8): confirm()/reject() verifican el estado
 * ANTES de escribir nada — una segunda llamada sobre un Payment que ya está
 * en ese estado terminal es un no-op completo (sin re-otorgar/re-extender
 * TrainingAccess, sin notificar de nuevo, sin volver a despachar
 * `PaymentConfirmed`). La guarda vive aquí, no en Filament, porque este es
 * el único punto de entrada real — cualquier canal futuro (ej. comando de
 * WhatsApp del superadmin, NO implementado todavía) hereda la misma
 * protección sin escribir nada propio.
 *
 * Hito 11 (D1, concurrencia real): la guarda anterior solo protegía contra
 * un SEGUNDO click secuencial — dos requests verdaderamente simultáneas
 * podían ambas leer `status != confirmed` antes de que la primera
 * escribiera, ambas ejecutar `grantAccess()`, y extender TrainingAccess
 * dos veces. `confirm()`/`reject()` ahora relockean y releen el Payment
 * DENTRO de una `DB::transaction()` (`lockForUpdate()`) — la segunda
 * transacción espera a que la primera termine y luego ve el estado ya
 * actualizado, cayendo en el mismo no-op de siempre.
 *
 * La notificación al cliente (App\Core\Notifications\CustomerNotifier) se
 * dispara DESPUÉS de que la transacción (estado + TrainingAccess + evento)
 * ya confirmó — deliberadamente FUERA del `lockForUpdate()`, para no
 * mantener la fila bloqueada durante una llamada HTTP real a Meta. Un
 * fallo de entrega nunca revierte ni bloquea la confirmación/rechazo
 * (CustomerNotifier nunca propaga excepciones, mismo contrato que
 * AlertService).
 *
 * Hito 11 (seam Referidos, D0XX): `PaymentConfirmed` se despacha dentro de
 * la misma transacción que confirma el Payment — es un hecho genérico de
 * Payments, sin ninguna lógica de Referidos ni de ningún otro dominio
 * futuro. `App\Payments` no importa ni depende de `App\Referrals` en
 * ningún punto de este archivo.
 *
 * Hito 15 (bugfix) — los 3 mensajes que este servicio envía vía
 * `CustomerNotifier` (`payment_confirmed`, `training_invite`,
 * `payment_rejected`) ahora pasan un `idempotencyKey` determinista
 * (`"{eventKey}:{payment->id}"`, mismo patrón textual que ya usa el
 * listener de Referidos con `"referral_reward:{id}"`) — antes no lo
 * hacían, inconsistencia inofensiva hoy (el `lockForUpdate()` de
 * confirm()/reject() ya impide una segunda ejecución de estas llamadas)
 * pero corregida para que el propio mecanismo de `CustomerNotifier` también
 * proteja estos 3 call-sites por sí mismo, sin depender únicamente del
 * candado externo. Sin cambio de comportamiento observable.
 */
class PaymentConfirmationService
{
    /**
     * Fallback EXCLUSIVO para Payments creados antes de este hito —
     * ninguno tiene `membership_months` (columna nueva, nullable). Nunca
     * se usa para un Payment nuevo, que siempre trae su propio snapshot
     * desde el MembershipPlan elegido. No confundir con una duración por
     * defecto de producto — es puramente de compatibilidad retroactiva.
     */
    private const LEGACY_DEFAULT_MONTHS = 1;

    public function __construct(
        private readonly CustomerNotifier $notifier,
    ) {}

    public function confirm(Payment $payment, ?User $reviewer, ?string $note = null): void
    {
        $access = null;

        /** @var ?Payment $confirmedNow */
        $confirmedNow = DB::transaction(function () use ($payment, $reviewer, $note, &$access) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Confirmed) {
                Log::info('PAYMENT_CONFIRM_IDEMPOTENT_NOOP', ['payment_id' => $locked->id]);

                return null;
            }

            $locked->update([
                'status' => PaymentStatus::Confirmed,
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $access = $this->grantAccess($locked);

            PaymentConfirmed::dispatch($locked);

            return $locked;
        });

        if ($confirmedNow === null) {
            return;
        }

        $this->notifyConfirmed($confirmedNow, $access);
        $this->notifyTrainingInvite($confirmedNow);
    }

    public function reject(Payment $payment, User $reviewer, string $reason): void
    {
        /** @var ?Payment $rejectedNow */
        $rejectedNow = DB::transaction(function () use ($payment, $reviewer, $reason) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Rejected) {
                Log::info('PAYMENT_REJECT_IDEMPOTENT_NOOP', ['payment_id' => $locked->id]);

                return null;
            }

            $locked->update([
                'status' => PaymentStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ]);

            return $locked;
        });

        if ($rejectedNow === null) {
            return;
        }

        $this->notifyRejected($rejectedNow, $reason);
    }

    /**
     * Hito 11 — concede/extiende exactamente la duración COMPLETA de la
     * membresía comprada (`Payment.membership_months`), nunca proporcional
     * al monto recibido — no existen pagos parciales (ver
     * docs/DECISIONS.md). Un Payment sin `membership_months` (creado antes
     * de este hito) usa `LEGACY_DEFAULT_MONTHS`, preservando exactamente
     * el comportamiento que ya tenía.
     */
    private function grantAccess(Payment $payment): TrainingAccess
    {
        $contact = $payment->contact;
        $months = $payment->membership_months ?? self::LEGACY_DEFAULT_MONTHS;

        $access = TrainingAccess::firstOrNew(['contact_id' => $contact->id]);

        // Un pago adelantado no debe perder días ya pagados — se extiende
        // desde el vencimiento actual si todavía está vigente, o desde
        // ahora si ya venció o nunca existió.
        $base = ($access->exists && $access->expires_at?->isFuture()) ? $access->expires_at : now();

        $access->fill([
            'payment_id' => $payment->id,
            'status' => TrainingAccessStatus::Active,
            'granted_at' => now(),
            'expires_at' => $base->copy()->addMonths($months),
            'granted_by' => 'payment_confirmation',
            'notes' => "Otorgado por Payment #{$payment->id} ({$months} mes(es))",
        ])->save();

        return $access;
    }

    // ── Notificación al cliente (CustomerNotifier) ──────────────────────

    private function notifyConfirmed(Payment $payment, TrainingAccess $access): void
    {
        $tenant = $payment->contact->tenant;
        $amount = number_format((float) $payment->amount, 0, ',', '.').' '.$payment->currency;
        $expiresAt = $access->expires_at->format('d/m/Y');

        $this->notifier->notify(
            tenant: $tenant,
            to: $payment->contact->customer_phone,
            eventKey: 'payment_confirmed',
            variables: [
                'amount' => $amount,
                'method' => $payment->method_label,
                'expires_at' => $expiresAt,
            ],
            freeFormText: "✅ Tu pago de {$amount} ({$payment->method_label}) fue confirmado. Tu acceso está activo hasta el {$expiresAt}. 💪",
            idempotencyKey: "payment_confirmed:{$payment->id}",
        );
    }

    /**
     * Segundo mensaje, proactivo — invita a iniciar el entrenamiento, nunca
     * lo inicia por sí mismo. NUNCA crea una WorkoutSession: eso solo ocurre
     * si el usuario responde y su mensaje entra por el flujo normal de
     * Training (Router → TrainingIntentClassifier → TrainingHandler), igual
     * que cualquier otro mensaje entrante — este método no conoce ni toca
     * TrainingEngine/TrainingHandler en absoluto.
     *
     * Comparte la misma guarda de idempotencia de confirm() (no se llama de
     * nuevo en un reintento sobre un Payment ya confirmado) y el mismo
     * aislamiento de fallos de CustomerNotifier (un fallo aquí no afecta a
     * notifyConfirmed() ni revierte nada ya persistido).
     */
    private function notifyTrainingInvite(Payment $payment): void
    {
        $tenant = $payment->contact->tenant;

        $this->notifier->notify(
            tenant: $tenant,
            to: $payment->contact->customer_phone,
            eventKey: 'training_invite',
            variables: [],
            freeFormText: '🎉 ¡Listo! Tu pago fue confirmado y tu acceso ya está activo. ¿Quieres que te prepare tu entrenamiento?',
            idempotencyKey: "training_invite:{$payment->id}",
        );
    }

    private function notifyRejected(Payment $payment, string $reason): void
    {
        $tenant = $payment->contact->tenant;

        $this->notifier->notify(
            tenant: $tenant,
            to: $payment->contact->customer_phone,
            eventKey: 'payment_rejected',
            variables: [
                'reason' => $reason,
            ],
            freeFormText: "❌ Tu pago no pudo confirmarse. Motivo: {$reason}. Escríbenos si quieres intentarlo de nuevo.",
            idempotencyKey: "payment_rejected:{$payment->id}",
        );
    }
}
