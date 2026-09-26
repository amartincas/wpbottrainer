<?php

namespace App\Training\Listeners;

use App\Core\Notifications\CustomerNotifier;
use App\Training\Events\DeclaredHealthConditionResolved;
use Illuminate\Support\Facades\Log;

/**
 * Hito O1 (Notificación proactiva de revisión de salud) — único consumidor
 * de `DeclaredHealthConditionResolved`. Responsabilidad EXCLUSIVA: traducir
 * el desenlace YA DECIDIDO (`DeclaredHealthConditionRecorder`, la única
 * autoridad real sobre la resolución) a un mensaje de WhatsApp — nunca
 * decide nada sobre la resolución en sí, nunca conoce `TrainingRestriction`/
 * `BodyRegion`/notas internas del admin (nunca se exponen al cliente, por
 * diseño — ver mensajes abajo).
 *
 * Mismo patrón EXACTO que `App\Referrals\Listeners\SendReferralIntroductionOnWorkoutCompleted`:
 * listener síncrono (no `ShouldQueue` — `CustomerNotifier` ya es best-effort
 * y no propaga excepciones, no hace falta cola/reintento aquí), con
 * `idempotencyKey` determinista — "como máximo una entrega CONFIRMADA" por
 * `declared_health_condition_id`, sin código nuevo de idempotencia.
 *
 * Textos marcados EXPLÍCITAMENTE como borrador de desarrollo — requieren
 * revisión de negocio/salud antes de producción real (mismo criterio que
 * `SafetySignalDetector::ESCALATION_MESSAGE`).
 */
class SendHealthReviewResolutionNotification
{
    // REQUIERE REVISIÓN DE NEGOCIO/PROFESIONAL ANTES DE PRODUCCIÓN
    private const MESSAGES = [
        'resolved_no_restriction' => [
            'event_key' => 'health_review_resolved_no_restriction',
            'text' => '¡Buenas noticias! Revisamos la información de salud que nos compartiste y ya puedes '
                .'continuar con tu entrenamiento con normalidad. Escríbeme cuando quieras seguir 💪',
        ],
        'resolved_restriction_created' => [
            'event_key' => 'health_review_resolved_with_restriction',
            'text' => 'Gracias por tu paciencia. Ya revisamos la información que nos compartiste y ajustamos tu '
                .'plan para evitar ejercicios que puedan afectarte. Ya puedes continuar con tu entrenamiento. '
                .'Escríbeme cuando quieras seguir 💪',
        ],
    ];

    public function __construct(private readonly CustomerNotifier $notifier) {}

    public function handle(DeclaredHealthConditionResolved $event): void
    {
        $condition = $event->condition;
        $copy = self::MESSAGES[$condition->status->value] ?? null;

        if ($copy === null) {
            // Defensivo: el Recorder solo despacha este evento tras una
            // resolución real a uno de los 2 estados de MESSAGES — un
            // estado distinto aquí (pending_review/superseded) nunca
            // debería ocurrir, pero nunca se envía un mensaje inventado
            // para un desenlace que este listener no traduce.
            Log::warning('HEALTH_REVIEW_NOTIFICATION_UNEXPECTED_STATUS', [
                'declared_health_condition_id' => $condition->id,
                'status' => $condition->status->value,
            ]);

            return;
        }

        $contact = $condition->contact;

        if ($contact === null) {
            Log::warning('HEALTH_REVIEW_NOTIFICATION_NO_CONTACT', [
                'declared_health_condition_id' => $condition->id,
            ]);

            return;
        }

        $this->notifier->notify(
            tenant: $contact->tenant,
            to: $contact->customer_phone,
            eventKey: $copy['event_key'],
            variables: [],
            freeFormText: $copy['text'],
            idempotencyKey: "health_review_resolved:{$condition->id}",
        );

        Log::info('HEALTH_REVIEW_RESOLUTION_NOTIFIED', [
            'contact_id' => $contact->id,
            'declared_health_condition_id' => $condition->id,
            'status' => $condition->status->value,
        ]);
    }
}
