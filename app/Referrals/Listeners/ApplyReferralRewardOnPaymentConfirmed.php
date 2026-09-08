<?php

namespace App\Referrals\Listeners;

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertService;
use App\Core\Alerts\AlertSeverity;
use App\Core\Notifications\CustomerNotifier;
use App\Models\Contact;
use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Events\PaymentConfirmed;
use App\Referrals\Enums\ReferralRewardApplicationStatus;
use App\Referrals\Models\Referral;
use App\Referrals\Models\ReferralReward;
use App\Referrals\Support\DetectsUniqueConstraintViolation;
use App\Training\Enums\TrainingAccessStatus;
use App\Training\Support\TrainingAccessAdministrationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El ÚNICO consumidor real de `App\Payments\Events\PaymentConfirmed` (Hito
 * 13 — el seam que Hito 11 dejó preparado, sin ningún listener real hasta
 * ahora). Reacciona al HECHO ya ocurrido — nunca escribe en `Payment`,
 * nunca conoce `PaymentConfirmationService`/`PaymentHandler`/
 * `PaymentValidationService` (ver `ReferralIsolationArchTest`).
 *
 * Registrado de forma síncrona (`Event::listen`, no `ShouldQueue`) — mismo
 * criterio que `PaymentConfirmationService` ya notifica de forma síncrona
 * hoy, sin introducir una nueva clase de fallo (cola) que no existe en
 * ningún otro punto de este dominio.
 *
 * Idempotencia/concurrencia (crítico, ver docs/DECISIONS.md):
 *  - "primera compra confirmada" NUNCA se decide con un `count() === 1`
 *    aislado — se bloquean (`lockForUpdate()`) TODOS los Payments
 *    `confirmed` del referido dentro de la transacción y se verifica que
 *    ESTE Payment sea el más antiguo visible (`reviewed_at`, `id`).
 *  - el INSERT de `ReferralReward` está protegido por DOS índices únicos
 *    (`referral_id`, `payment_id`) — si dos confirmaciones concurrentes de
 *    Payments distintos del mismo referido ambas se creen "la primera", la
 *    base de datos decide cuál gana; la perdedora captura la violación
 *    como no-op, nunca como error.
 */
class ApplyReferralRewardOnPaymentConfirmed
{
    use DetectsUniqueConstraintViolation;

    public function __construct(
        private readonly TrainingAccessAdministrationService $trainingAccessAdmin,
        private readonly CustomerNotifier $notifier,
        private readonly AlertService $alerts,
    ) {}

    public function handle(PaymentConfirmed $event): void
    {
        $payment = $event->payment;

        $referral = Referral::where('referred_contact_id', $payment->contact_id)->first();

        if ($referral === null || $referral->isRewarded()) {
            // Sin atribución, o ya recompensado antes — no-op silencioso.
            return;
        }

        $result = DB::transaction(function () use ($payment, $referral) {
            // Bloquea TODOS los Payments confirmados de este referido —
            // serializa cualquier ejecución concurrente de este mismo
            // listener para el mismo referido, y fuerza a la más lenta a
            // releer el estado ya actualizado.
            $confirmedPayments = Payment::where('contact_id', $payment->contact_id)
                ->where('status', PaymentStatus::Confirmed)
                ->lockForUpdate()
                ->orderBy('reviewed_at')
                ->orderBy('id')
                ->get();

            $earliest = $confirmedPayments->first();

            if ($earliest === null || $earliest->id !== $payment->id) {
                // Este Payment NO es la primera compra confirmada del
                // referido — ya existe uno más antiguo (o ya ganó la
                // carrera otra ejecución concurrente). No-op.
                return null;
            }

            $referrerContact = $referral->referrerContact;
            $rewardDays = $referrerContact->tenant->referral_reward_days;
            $applicationStatus = $this->determineApplicationStatus($referrerContact);

            try {
                $reward = ReferralReward::create([
                    'referral_id' => $referral->id,
                    'payment_id' => $payment->id,
                    'reward_days' => $rewardDays,
                    'application_status' => $applicationStatus,
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e)) {
                    throw $e;
                }

                Log::info('REFERRAL_REWARD_IDEMPOTENT_NOOP', ['referral_id' => $referral->id, 'payment_id' => $payment->id]);

                return null;
            }

            if ($applicationStatus === ReferralRewardApplicationStatus::Applied) {
                $this->trainingAccessAdmin->extendByDays(
                    $referrerContact,
                    null,
                    $rewardDays,
                    "Recompensa por referido — Payment #{$payment->id} confirmado",
                    $reward->id,
                );
            }

            if ($applicationStatus === ReferralRewardApplicationStatus::Pending) {
                $this->emitPendingAlert($reward, $referrerContact, $payment);
            }

            return $reward;
        });

        if ($result !== null && $result->application_status !== ReferralRewardApplicationStatus::Pending) {
            $this->notifyReferrer($result);
        }
    }

    private function determineApplicationStatus(Contact $referrerContact): ReferralRewardApplicationStatus
    {
        $access = $referrerContact->trainingAccess;

        if ($access === null || $access->status === TrainingAccessStatus::Revoked) {
            return ReferralRewardApplicationStatus::Pending;
        }

        if ($access->expires_at === null) {
            return ReferralRewardApplicationStatus::NotApplicable;
        }

        return ReferralRewardApplicationStatus::Applied;
    }

    private function notifyReferrer(ReferralReward $reward): void
    {
        $referrerContact = $reward->referral->referrerContact;

        $this->notifier->notify(
            tenant: $referrerContact->tenant,
            to: $referrerContact->customer_phone,
            eventKey: 'referral_reward_applied',
            variables: ['reward_days' => (string) $reward->reward_days],
            freeFormText: "🎁 ¡Buenas noticias! Uno de tus referidos activó su membresía y ganaste {$reward->reward_days} días adicionales de entrenamiento. 💪",
            idempotencyKey: "referral_reward:{$reward->id}",
        );
    }

    private function emitPendingAlert(ReferralReward $reward, Contact $referrerContact, Payment $payment): void
    {
        try {
            $this->alerts->send(new Alert(
                category: 'referrals',
                severity: AlertSeverity::Warning,
                message: "Recompensa de referido pendiente de aplicar — el referente #{$referrerContact->id} ".
                    "ganó {$reward->reward_days} días (Payment #{$payment->id} confirmado) pero su TrainingAccess ".
                    'está revocado o no existe. No se auto-reactiva — requiere revisión manual (Reactivar + Extender).',
                context: [
                    'tenant_id' => $referrerContact->tenant_id,
                    'referral_reward_id' => $reward->id,
                    'referrer_contact_id' => $referrerContact->id,
                    'payment_id' => $payment->id,
                ],
            ));
        } catch (\Throwable $e) {
            Log::error('REFERRAL_PENDING_ALERT_EMIT_FAILED', ['referral_reward_id' => $reward->id, 'error' => $e->getMessage()]);
        }
    }
}
