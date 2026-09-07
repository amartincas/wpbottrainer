<?php

namespace App\Training\Support;

use App\Training\Enums\DetectedIntentType;

/**
 * Bloque 9 (D052) — determinista, sin IA, sin escritura propia a base de
 * datos (delega). Recibe el resultado ya parseado de la única llamada de
 * IA del turno (`safety_signal_text`, `report`, `intents`, `training_reply`)
 * y decide, con una prioridad FIJA en código — nunca en el orden en que la
 * IA listó los intents — qué acciones ejecutar y en qué orden.
 *
 * Prioridad (D052): Safety > Execution/reporte > Training/Coach > Commercial
 * > FAQ > fallback ambiguo. Solo `EscalateSafety` detiene el resto —
 * `RecordExecutionReport` se ejecuta y el resolver SIGUE evaluando el resto
 * de intents del mismo turno (aprobado explícitamente: un mensaje puede
 * combinar un reporte real con una pregunta de otro dominio).
 *
 * `training_reply` se usa como máximo UNA vez por turno, sin importar
 * cuántos intents de dominio entrenamiento (`exercise_question`,
 * `general_conversation`) aparezcan juntos — una pregunta compuesta de
 * entrenamiento sigue siendo una sola explicación coherente.
 *
 * `continue_training` NUNCA depende de `training_reply` — siempre dispara
 * `DeliverSession`, que `TrainingHandler` traduce en la llamada ya existente
 * a `TrainingEngine::decideNextSession()` (sin cambios ahí).
 *
 * `membership_status`/`faq_question` SIEMPRE usan el stub fijo — nunca un
 * texto libre generado por la IA para esos dominios (Commercial/FAQ no
 * implementados en este bloque).
 */
class ConversationTurnResolver
{
    /**
     * // REQUIERE REVISIÓN DE NEGOCIO ANTES DE PRODUCCIÓN — texto stub
     * temporal mientras no exista un CommercialHandler real.
     */
    private const COMMERCIAL_STUB = 'Todavía no puedo resolver esto directamente por aquí, pero un miembro de '
        .'nuestro equipo puede ayudarte con tu membresía. 💬';

    /**
     * // REQUIERE REVISIÓN DE NEGOCIO ANTES DE PRODUCCIÓN — texto stub
     * temporal mientras no exista un FaqHandler real.
     */
    private const FAQ_STUB = 'Todavía no puedo resolver ese tipo de preguntas directamente por aquí, pero un '
        .'miembro de nuestro equipo puede ayudarte. 💬';

    private const AMBIGUOUS_TURN_FALLBACK = 'No estoy segura de haber entendido — ¿me cuentas cómo te fue con tu '
        .'entrenamiento, o en qué más te ayudo?';

    private const TRAINING_REPLY_INTENTS = [
        DetectedIntentType::ExerciseQuestion->value,
        DetectedIntentType::GeneralConversation->value,
    ];

    public function __construct(private readonly SafetySignalDetector $safetyDetector) {}

    /**
     * @param  array{safety_signal_text: ?string, reports?: array, session_finished?: bool, intents: array<int, string>, training_reply: ?string}  $result
     *         Mismo formato plano que ya devuelve `ExecutionReportService::extractReport()`
     *         (`reports`/`session_finished` como claves de primer nivel, no
     *         anidadas) — el resultado de `CoachService::respond()` simplemente
     *         no las incluye, por lo que esta rama nunca se activa en ese camino.
     */
    public function resolve(array $result): ConversationTurnResolved
    {
        $safetySignalText = $result['safety_signal_text'] ?? null;

        if ($safetySignalText !== null) {
            // Nunca se confía en que la IA decida por sí sola que algo es
            // una señal de seguridad — se re-verifica siempre con el
            // detector determinista (mismo patrón que onboarding, D026).
            $confirmedCategory = $this->safetyDetector->detect($safetySignalText);

            if ($confirmedCategory !== null) {
                return new ConversationTurnResolved([ConversationAction::escalateSafety($confirmedCategory)]);
            }
        }

        $actions = [];

        $reports = $result['reports'] ?? [];
        $sessionFinished = ($result['session_finished'] ?? false) === true;

        if ($reports !== [] || $sessionFinished) {
            $actions[] = ConversationAction::recordExecutionReport([
                'reports' => $reports,
                'session_finished' => $sessionFinished,
            ]);
        }

        $intents = $result['intents'] ?? [];
        $trainingReply = $result['training_reply'] ?? null;

        if (array_intersect(self::TRAINING_REPLY_INTENTS, $intents) !== [] && is_string($trainingReply) && trim($trainingReply) !== '') {
            $actions[] = ConversationAction::sendText($trainingReply);
        }

        if (in_array(DetectedIntentType::ContinueTraining->value, $intents, true)) {
            $actions[] = ConversationAction::deliverSession();
        }

        // Hito 10 — datos CRUDOS únicamente: ni resueltos ni validados aquí
        // (ConversationTurnResolver sigue sin conocer Contact/base de datos/
        // timezone). `TrainingHandler` decide, al ejecutar, si hay algo real
        // a lo que aplicar esto (una ReminderSuggestion pendiente / un
        // Reminder activo) — mismo patrón que RecordExecutionReport.
        if (in_array(DetectedIntentType::ReminderRequest->value, $intents, true)) {
            $actions[] = ConversationAction::proposeReminder([
                'day' => $result['reminder_day'] ?? null,
                'time' => $result['reminder_time'] ?? null,
                'recurring' => ($result['reminder_recurrence'] ?? false) === true,
            ]);
        }

        $reminderConfirmation = $result['reminder_confirmation'] ?? null;

        if ($reminderConfirmation !== null) {
            $actions[] = ConversationAction::applyReminderDecision([
                'decision' => 'confirmation',
                'confirmed' => $reminderConfirmation === true,
                'day' => $result['reminder_day'] ?? null,
                'time' => $result['reminder_time'] ?? null,
            ]);
        }

        if (in_array(DetectedIntentType::ReminderCancel->value, $intents, true)) {
            $actions[] = ConversationAction::applyReminderDecision(['decision' => 'cancel', 'confirmed' => null, 'day' => null, 'time' => null]);
        }

        if (in_array(DetectedIntentType::ReminderModify->value, $intents, true)) {
            $actions[] = ConversationAction::applyReminderDecision([
                'decision' => 'modify', 'confirmed' => null,
                'day' => $result['reminder_day'] ?? null,
                'time' => $result['reminder_time'] ?? null,
            ]);
        }

        if (in_array(DetectedIntentType::MembershipStatus->value, $intents, true)) {
            $actions[] = ConversationAction::sendText(self::COMMERCIAL_STUB);
        }

        if (in_array(DetectedIntentType::FaqQuestion->value, $intents, true)) {
            $actions[] = ConversationAction::sendText(self::FAQ_STUB);
        }

        if ($actions === []) {
            $actions[] = ConversationAction::sendText(self::AMBIGUOUS_TURN_FALLBACK);
        }

        return new ConversationTurnResolved($actions);
    }
}
