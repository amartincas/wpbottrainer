<?php

namespace App\Payments\Support;

use App\Core\Notifications\CustomerNotifier;
use App\Models\Payment;
use App\Models\TrainingAccess;
use App\Models\User;
use App\Payments\Enums\PaymentStatus;
use App\Training\Enums\TrainingAccessStatus;
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
 * TrainingAccess, sin notificar de nuevo). La guarda vive aquí, no en
 * Filament, porque este es el único punto de entrada real — cualquier canal
 * futuro (ej. comando de WhatsApp del superadmin, NO implementado todavía)
 * hereda la misma protección sin escribir nada propio.
 *
 * La notificación al cliente (App\Core\Notifications\CustomerNotifier) se
 * dispara DESPUÉS de que el cambio de estado (y, si aplica, TrainingAccess)
 * ya quedó persistido — un fallo de entrega nunca revierte ni bloquea la
 * confirmación/rechazo (CustomerNotifier nunca propaga excepciones, mismo
 * contrato que AlertService).
 */
class PaymentConfirmationService
{
    private const ACCESS_PERIOD_MONTHS = 1;

    public function __construct(
        private readonly CustomerNotifier $notifier,
    ) {}

    public function confirm(Payment $payment, ?User $reviewer, ?string $note = null): void
    {
        if ($payment->status === PaymentStatus::Confirmed) {
            Log::info('PAYMENT_CONFIRM_IDEMPOTENT_NOOP', ['payment_id' => $payment->id]);

            return;
        }

        $payment->update([
            'status' => PaymentStatus::Confirmed,
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $access = $this->grantAccess($payment);

        $this->notifyConfirmed($payment, $access);
        $this->notifyTrainingInvite($payment);
    }

    public function reject(Payment $payment, User $reviewer, string $reason): void
    {
        if ($payment->status === PaymentStatus::Rejected) {
            Log::info('PAYMENT_REJECT_IDEMPOTENT_NOOP', ['payment_id' => $payment->id]);

            return;
        }

        $payment->update([
            'status' => PaymentStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $reason,
        ]);

        $this->notifyRejected($payment, $reason);
    }

    private function grantAccess(Payment $payment): TrainingAccess
    {
        $contact = $payment->contact;

        $access = TrainingAccess::firstOrNew(['contact_id' => $contact->id]);

        // Un pago adelantado no debe perder días ya pagados — se extiende
        // desde el vencimiento actual si todavía está vigente, o desde
        // ahora si ya venció o nunca existió.
        $base = ($access->exists && $access->expires_at?->isFuture()) ? $access->expires_at : now();

        $access->fill([
            'payment_id' => $payment->id,
            'status' => TrainingAccessStatus::Active,
            'granted_at' => now(),
            'expires_at' => $base->copy()->addMonths(self::ACCESS_PERIOD_MONTHS),
            'granted_by' => 'payment_confirmation',
            'notes' => "Otorgado por Payment #{$payment->id}",
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
        );
    }
}
