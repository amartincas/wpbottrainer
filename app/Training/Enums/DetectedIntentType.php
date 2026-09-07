<?php

namespace App\Training\Enums;

/**
 * Bloque 9 (D052) — vocabulario cerrado de intents que un turno puede
 * contener. Deliberadamente una COLECCIÓN (`intents: string[]` en el
 * contrato JSON de la IA), nunca una clasificación excluyente — un mismo
 * mensaje puede combinar varios (ej. una pregunta de entrenamiento y una
 * pregunta comercial en la misma frase). La prioridad de ejecución la
 * decide `ConversationTurnResolver` en código, nunca el orden en que la IA
 * los listó.
 *
 * `execution_report` NO es un valor de este enum — ya está representado,
 * sin duplicar el concepto, por el campo `report` del contrato JSON (no
 * vacío o `session_finished=true`), exactamente el contrato ya validado de
 * `ExecutionReportService` desde el Bloque 6.
 *
 * `safety_issue` tampoco es un valor de este enum — la seguridad se
 * resuelve mediante el campo `safety_signal_text`, re-verificado siempre
 * por `SafetySignalDetector` (determinista), nunca confiando en que la IA
 * decida por sí sola que algo es una señal de seguridad (mismo patrón que
 * ya usa `OnboardingConversationService` desde D026).
 */
enum DetectedIntentType: string
{
    case ExerciseQuestion = 'exercise_question';
    case ContinueTraining = 'continue_training';
    case GeneralConversation = 'general_conversation';
    case MembershipStatus = 'membership_status';
    case FaqQuestion = 'faq_question';

    /**
     * Hito 10 — cubre tanto una petición explícita ("recuérdame mañana a
     * las 7", "todos los martes recuérdame entrenar") como una mención que
     * dispara una OFERTA proactiva ("siempre se me olvida entrenar los
     * martes") — en ambos casos el código decide qué hacer con `reminder_day`/
     * `reminder_time`/`reminder_recurrence`, nunca la IA.
     */
    case ReminderRequest = 'reminder_request';
    case ReminderCancel = 'reminder_cancel';
    case ReminderModify = 'reminder_modify';

    /**
     * Valida una lista cruda (ej. del JSON de la IA) contra este vocabulario
     * cerrado — cualquier valor que no sea uno de estos casos se descarta
     * silenciosamente (nunca un error, nunca una segunda autoridad de
     * clasificación). Reutilizado por `CoachService` y por
     * `ExecutionReportService` para no duplicar esta validación.
     *
     * @return array<int, string>
     */
    public static function validateList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $valid = array_map(fn (self $case) => $case->value, self::cases());

        return array_values(array_intersect(array_filter($value, 'is_string'), $valid));
    }
}
