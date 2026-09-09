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
 * `membership_status` SIEMPRE usa el stub fijo — Commercial no implementado
 * todavía (fuera de alcance de Hito 14).
 *
 * Hito 14 — `faq_question`/`customer_service_needed`/`customer_service_request`
 * YA NO usan un stub fijo: `faq_response_text`/`customer_service_message`
 * llegan REDACTADOS por la IA en la MISMA llamada ya resuelta más arriba
 * (`CoachService`) — este resolver sigue sin conocer `App\CustomerCare`, sin
 * BD, solo decide QUÉ acción crear con el texto que ya recibió. Ver
 * docs/DECISIONS.md.
 */
class ConversationTurnResolver
{
    /**
     * // REQUIERE REVISIÓN DE NEGOCIO ANTES DE PRODUCCIÓN — texto stub
     * temporal mientras no exista un CommercialHandler real.
     */
    private const COMMERCIAL_STUB = 'Todavía no puedo resolver esto directamente por aquí, pero un miembro de '
        .'nuestro equipo puede ayudarte con tu membresía. 💬';

    private const AMBIGUOUS_TURN_FALLBACK = 'No estoy segura de haber entendido — ¿me cuentas cómo te fue con tu '
        .'entrenamiento, o en qué más te ayudo?';

    private const TRAINING_REPLY_INTENTS = [
        DetectedIntentType::ExerciseQuestion->value,
        DetectedIntentType::GeneralConversation->value,
        // Hito 10 (D053) — "asked_when_to_train" puede llevar una respuesta
        // conversacional propia (ej. "según tu plan, entrenas martes y
        // viernes") ADEMÁS de la oferta proactiva de OfferProactiveReminder
        // — son dos acciones/mensajes independientes, nunca uno sustituye
        // al otro.
        DetectedIntentType::AskedWhenToTrain->value,
    ];

    public function __construct(private readonly SafetySignalDetector $safetyDetector) {}

    /**
     * @param  array{safety_signal_text: ?string, reports?: array, session_finished?: bool, intents: array<int, string>, training_reply: ?string, faq_match_id?: ?int, faq_response_text?: ?string, customer_service_needed?: bool, customer_service_message?: ?string}  $result
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

        // Hito 10 (D053, corrección post-revisión) — Triggers 1/3 de
        // proactividad: SEÑALES, nunca peticiones explícitas — el trigger
        // reason es literalmente el valor del intent detectado (sin tabla
        // de mapeo separada que pudiera desincronizarse). El código
        // (ReminderProactivityGate, en TrainingHandler) decide si
        // corresponde ofrecer algo; este resolver solo transporta CUÁL
        // señal apareció. Si ambas coinciden en el mismo mensaje (raro), se
        // prioriza la más específica de las dos (mentioned_forgetting).
        if (in_array(DetectedIntentType::MentionedForgettingToTrain->value, $intents, true)) {
            $actions[] = ConversationAction::offerProactiveReminder(['trigger_reason' => DetectedIntentType::MentionedForgettingToTrain->value]);
        } elseif (in_array(DetectedIntentType::AskedWhenToTrain->value, $intents, true)) {
            $actions[] = ConversationAction::offerProactiveReminder(['trigger_reason' => DetectedIntentType::AskedWhenToTrain->value]);
        }

        if (in_array(DetectedIntentType::MembershipStatus->value, $intents, true)) {
            $actions[] = ConversationAction::sendText(self::COMMERCIAL_STUB);
        }

        // Hito 14 — FAQ/Customer Service como interrupciones conversacionales
        // (ver docs/DECISIONS.md). `faq_response_text`/`customer_service_message`
        // ya vienen REDACTADOS por la IA (la única llamada del turno, ya
        // hecha) — este resolver sigue sin conocer `App\CustomerCare`, solo
        // transporta los textos ya finales.
        $faqResponseText = $result['faq_response_text'] ?? null;

        if (is_string($faqResponseText) && trim($faqResponseText) !== '') {
            $actions[] = ConversationAction::answerFaq($faqResponseText);
        }

        $needsCustomerService = false;
        $isFaqFallback = false;
        $customerServiceMessage = null;

        if (($result['customer_service_needed'] ?? false) === true) {
            $needsCustomerService = true;
            $isFaqFallback = true;
            $rawMessage = $result['customer_service_message'] ?? null;
            $customerServiceMessage = is_string($rawMessage) && trim($rawMessage) !== '' ? $rawMessage : null;
        } elseif (in_array(DetectedIntentType::CustomerServiceRequest->value, $intents, true)) {
            $needsCustomerService = true; // petición explícita, isFaqFallback queda false
        }

        // Red de seguridad: faq_question detectado sin respuesta válida y
        // sin escalación ya marcada (violación de contrato de la IA) -> se
        // fuerza igual, nunca se queda en silencio.
        $faqUnresolved = in_array(DetectedIntentType::FaqQuestion->value, $intents, true)
            && ! (is_string($faqResponseText) && trim($faqResponseText) !== '');

        if ($faqUnresolved && ! $needsCustomerService) {
            $needsCustomerService = true;
            $isFaqFallback = true;
        }

        if ($needsCustomerService) {
            $actions[] = ConversationAction::requestCustomerService($customerServiceMessage, $isFaqFallback);
        }

        if ($actions === []) {
            $actions[] = ConversationAction::sendText(self::AMBIGUOUS_TURN_FALLBACK);
        }

        return new ConversationTurnResolved($actions);
    }
}
