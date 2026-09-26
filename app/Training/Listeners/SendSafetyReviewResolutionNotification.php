<?php

namespace App\Training\Listeners;

use App\Core\Notifications\CustomerNotifier;
use App\Training\Events\TrainingProfileSafetyFlagCleared;
use Illuminate\Support\Facades\Log;

/**
 * Hito O1 (Notificación proactiva de revisión de salud) — único consumidor
 * de `TrainingProfileSafetyFlagCleared`. Mismo criterio de responsabilidad
 * exclusiva que `SendHealthReviewResolutionNotification`: solo traduce el
 * hecho ya ocurrido (`TrainingProfile::clearSafetyFlag()`) a un mensaje de
 * WhatsApp — nunca conoce `safety_flag_reason`/notas internas del admin.
 *
 * `idempotencyKey` incorpora `$event->previousFlaggedAt` (nunca el estado
 * actual del perfil, ya limpio) — distingue ciclos flag→clear repetidos
 * del mismo contacto, ver docblock de `TrainingProfileSafetyFlagCleared`.
 *
 * Texto marcado EXPLÍCITAMENTE como borrador de desarrollo — requiere
 * revisión de negocio/salud antes de producción real.
 */
class SendSafetyReviewResolutionNotification
{
    // REQUIERE REVISIÓN DE NEGOCIO/PROFESIONAL ANTES DE PRODUCCIÓN
    private const MESSAGE_TEXT = 'Gracias por tu paciencia — ya revisamos tu caso y puedes continuar con tu '
        .'entrenamiento con normalidad. Recuerda siempre priorizar tu seguridad. Escríbeme cuando quieras seguir 💪';

    public function __construct(private readonly CustomerNotifier $notifier) {}

    public function handle(TrainingProfileSafetyFlagCleared $event): void
    {
        $contact = $event->profile->contact;

        if ($contact === null) {
            Log::warning('SAFETY_REVIEW_NOTIFICATION_NO_CONTACT', [
                'training_profile_id' => $event->profile->id,
            ]);

            return;
        }

        // `?? 'unknown'` es puramente defensivo (un perfil marcado
        // FlaggedForReview siempre tiene safety_flagged_at poblado por
        // flagForSafetyReview()) — nunca bloquea el envío por falta de este
        // dato histórico.
        $timestampPart = $event->previousFlaggedAt?->timestamp ?? 'unknown';

        $this->notifier->notify(
            tenant: $contact->tenant,
            to: $contact->customer_phone,
            eventKey: 'safety_review_resolved',
            variables: [],
            freeFormText: self::MESSAGE_TEXT,
            idempotencyKey: "safety_review_resolved:{$contact->id}:{$timestampPart}",
        );

        Log::info('SAFETY_REVIEW_RESOLUTION_NOTIFIED', [
            'contact_id' => $contact->id,
            'training_profile_id' => $event->profile->id,
        ]);
    }
}
