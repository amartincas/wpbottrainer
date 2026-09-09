<?php

namespace App\Training\Handlers;

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertService;
use App\Core\Alerts\AlertSeverity;
use App\Core\Memory\ContextBuilder;
use App\Core\Memory\ContextFragment;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\HandlerInterface;
use App\CustomerCare\Support\CustomerServiceRequestRecorder;
use App\CustomerCare\Support\FaqMatcher;
use App\ExerciseCatalog\MediaResolver;
use App\Models\Contact;
use App\Models\Reminder;
use App\Models\ReminderSuggestion;
use App\Models\Tenant;
use App\Models\TrainingProfile;
use App\Models\WhatsAppMessage;
use App\Models\WorkoutSession;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\ConversationActionType;
use App\Training\Enums\ReminderSuggestionOrigin;
use App\Training\Enums\ReminderSuggestionStatus;
use App\Training\Enums\SafetyStatus;
use App\Training\Enums\SplitType;
use App\Training\Onboarding\OnboardingConversationComposer;
use App\Training\Onboarding\OnboardingRequirementRegistry;
use App\Training\Support\CoachService;
use App\Training\Support\ConversationTurnResolved;
use App\Training\Support\ConversationTurnResolver;
use App\Training\Support\ExecutionReportOutcome;
use App\Training\Support\ExecutionReportRecorder;
use App\Training\Support\ExecutionReportService;
use App\Training\Support\ExerciseMessageFormatter;
use App\Training\Support\OnboardingConversationService;
use App\Training\Support\ReminderProactivityGate;
use App\Training\Support\ReminderTimeResolver;
use App\Training\Support\SafetySignalDetector;
use App\Training\Support\TimezoneResolver;
use App\Training\Support\TrainingAccessDeniedException;
use App\Training\Support\TrainingAccessGate;
use Illuminate\Support\Facades\Log;

/**
 * The Training conversational flow (Hito 5, extended en Hito 6, Bloque 9):
 *
 *   ¿señal de seguridad?         -> escalar y detener
 *   ¿perfil incompleto?          -> onboarding conversacional (Extract -> Decide -> Narrate)
 *   ¿sin acceso?                 -> informar que debe activarse el servicio
 *   ¿contexto activo (sesión pendiente con ejercicios sin reportar)?
 *        -> ExecutionReportService (evolucionado, Bloque 9/D052): ÚNICA
 *           llamada de IA del turno — clasifica reporte + interrupciones
 *           conversacionales (`intents`) a la vez -> ConversationTurnResolver
 *           decide, en código, qué acciones ejecutar y en qué orden ->
 *           SIEMPRE resuelve el turno, nunca cae al paso siguiente.
 *   en otro caso
 *        -> CoachService (Bloque 9/D052): ÚNICA llamada de IA de este
 *           camino (hoy inexistente) -> ConversationTurnResolver ->
 *           `continue_training` dispara determinísticamente
 *           TrainingEngine::decideNextSession() (sin cambios); cualquier
 *           otro intent responde sin generar una sesión nueva.
 *
 * Orchestration only: every real decision is delegated to a domain service
 * (SafetySignalDetector, OnboardingConversationService, TrainingAccessGate,
 * TrainingEngine, ExecutionReportService, ExecutionReportRecorder,
 * CoachService, ConversationTurnResolver) — this class does not contain
 * business rules of its own. Stateless: tenant/contact/message data are
 * local variables, never instance properties.
 *
 * Bloque 9 (D052) — principio central: el contexto activo (la sesión
 * `Scheduled` pendiente, ya representada por `active_workout_session`, sin
 * ninguna tabla nueva) es independiente de los `intents` detectados en el
 * mensaje actual. Una interrupción conversacional (pregunta comercial,
 * duda general) nunca modifica ni destruye esa sesión — `ConversationTurnResolver`
 * solo delega en `ExecutionReportRecorder` cuando el mensaje realmente
 * contiene un reporte; el resto de acciones (`SendText`, `EscalateSafety`)
 * nunca tocan `WorkoutSession`/`WorkoutExercise`. Coach es puramente
 * conversacional — nunca decide ejercicio/carga/reps/progresión/seguridad;
 * `TrainingEngine` sigue siendo la única autoridad de prescripción,
 * `SafetySignalDetector` la única autoridad de seguridad (una señal
 * propuesta por la IA siempre se re-verifica de forma determinista antes de
 * escalar, mismo patrón que ya usa onboarding, D026). `MembershipStatus`/
 * `FaqQuestion` responden con un stub fijo — sin dominio real implementado
 * todavía.
 *
 * No hay un Intent nuevo de Core para "reportar ejecución"/"interrupción
 * conversacional" — el Router (Hito 5) sigue clasificando únicamente
 * `training`; toda esta resolución es interna a Training (`DetectedIntentType`),
 * deliberadamente no promovida a `App\Core\Messaging\Intent` en este bloque.
 *
 * Memoria conversacional (Hito 6, ampliada en Bloque 9): toda entrada y
 * salida relevante se persiste en WhatsAppMessage (ver logInbound()/reply())
 * — separada de ExerciseLog/ExerciseSet, que representan hechos de
 * entrenamiento, no conversación. Los últimos mensajes (`CoachContext->recentMessages`)
 * son, para el LLM, contexto lingüístico — nunca una fuente de hechos ni de
 * instrucciones (ver `CoachFactsFormatter`).
 *
 * Hito 14 — FAQ/Customer Service como interrupciones conversacionales
 * dentro del MISMO turno de Coach (paso 5): `AnswerFaq`/`RequestCustomerService`
 * nunca tocan WorkoutSession/TrainingProfile/onboarding_turns — el contexto
 * de Training queda intacto. `FaqMatcher::sanitize()` (validación backend
 * en memoria) se aplica ANTES de `ConversationTurnResolver::resolve()`. El
 * camino de reporte activo (paso 4, `ExecutionReportService`) no recibe
 * candidatos de FAQ — si el modelo igual marca `faq_question` ahí, el
 * mismo `ConversationTurnResolver` degrada de forma segura hacia Customer
 * Service (nunca una respuesta inventada) — límite conocido y documentado,
 * no una implementación completa de FAQ en ese camino (ver docs/DECISIONS.md).
 */
class TrainingHandler implements HandlerInterface
{
    private const ACCESS_REQUIRED_MESSAGE = 'Tu perfil ya está listo. 💪 Para comenzar a entrenar necesitas activar '
        .'tu acceso. Escribe "quiero pagar" para ver las opciones.';

    /**
     * Bloque 9 (D052) — `continue_training` nunca genera ni reenvía una
     * rutina cuando ya existe una sesión pendiente (contexto activo): se
     * informa brevemente en vez de dejar el turno sin ninguna respuesta.
     */
    private const PENDING_SESSION_REMINDER = 'Ya tienes una sesión de entrenamiento pendiente. Cuéntame cómo te fue '
        .'con los ejercicios cuando la completes 💪';

    /**
     * Bloque 5 — mostrado cuando `TrainingAccessGate` deniega con
     * 'health_screening_pending': una `DeclaredHealthCondition` sigue
     * `pending_review` para un contacto sin ninguna WorkoutSession todavía.
     * Nunca afirma nada médico, nunca promete un plazo — solo informa que
     * hay una revisión humana en curso.
     */
    private const HEALTH_SCREENING_PENDING_MESSAGE = 'Gracias por contarme. Antes de armar tu primera rutina, '
        .'un miembro de nuestro equipo va a revisar la información que compartiste para asegurarnos de adaptarla '
        .'bien. Te aviso en cuanto esté lista 💪';

    /**
     * Hito 10 — atajo determinista (cero IA) para una afirmación corta
     * dentro de la ventana de continuidad de un Reminder ya disparado (ver
     * `Reminder.awaiting_response_until`, D053). Vocabulario cerrado y
     * curado, mismo criterio que `SafetySignalDetector::PATTERNS`.
     */
    private const SHORT_AFFIRMATIVE_WORDS = [
        'si', 'sí', 'dale', 'listo', 'vamos', 'empecemos', 'ok', 'okay', 'va', 'bueno',
    ];

    private const REMINDER_CLARIFICATION_MESSAGE = '¿Qué día y a qué hora quieres que te recuerde? '
        .'Por ejemplo: "todos los martes a las 7pm" o "mañana a las 8am".';

    private const REMINDER_PROPOSAL_TEMPLATE = 'Puedo recordarte entrenar %s a las %s. ¿Confirmas? 💪';

    private const REMINDER_CONFIRMED_MESSAGE = '¡Listo! Te recordaré entrenar %s a las %s. 🔔';

    private const REMINDER_DECLINED_MESSAGE = 'Sin problema, no configuro ningún recordatorio.';

    private const REMINDER_NONE_ACTIVE_MESSAGE = 'No tienes ningún recordatorio activo en este momento.';

    private const REMINDER_ALREADY_ACTIVE_MESSAGE = 'Ya tienes un recordatorio activo — cancélalo primero si quieres configurar uno nuevo.';

    private const REMINDER_CANCELLED_MESSAGE = 'Listo, cancelé tu recordatorio. 🔕';

    private const REMINDER_MODIFIED_MESSAGE = 'Listo, actualicé tu recordatorio para %s a las %s. 🔔';

    /**
     * // DECISIÓN DE NEGOCIO PENDIENTE — valor técnico provisional,
     * compartido por los 3 triggers MVP de proactividad (D053).
     */
    private const PROACTIVE_OFFER_TIME = '19:00';

    /**
     * Hito 10 (D053, corrección post-revisión) — `trigger_reason` de
     * Trigger 2 ("terminó una sesión"): no proviene de un `DetectedIntentType`
     * (se decide por `ExecutionReportOutcome::sessionCompleted`, no por la
     * IA), así que no comparte enum con los Triggers 1/3 — se declara aquí
     * como su propia constante, con el mismo criterio de nomenclatura.
     */
    private const PROACTIVE_TRIGGER_SESSION_COMPLETED = 'session_completed';

    public function __construct(
        private readonly TrainingAccessGate $accessGate,
        private readonly TrainingEngine $engine,
        private readonly SafetySignalDetector $safetyDetector,
        private readonly OnboardingConversationService $onboarding,
        private readonly OnboardingRequirementRegistry $requirementRegistry,
        private readonly OnboardingConversationComposer $composer,
        private readonly ExecutionReportService $reportExtractor,
        private readonly ExecutionReportRecorder $reportRecorder,
        private readonly ContextBuilder $contextBuilder,
        private readonly AlertService $alerts,
        private readonly MediaResolver $mediaResolver,
        private readonly ExerciseMessageFormatter $messageFormatter,
        private readonly CoachService $coach,
        private readonly ConversationTurnResolver $turnResolver,
        private readonly ReminderTimeResolver $reminderTimeResolver,
        private readonly TimezoneResolver $timezoneResolver,
        private readonly ReminderProactivityGate $proactivityGate,
        private readonly FaqMatcher $faqMatcher,
        private readonly CustomerServiceRequestRecorder $customerServiceRecorder,
    ) {}

    public function handle(ExecutionContext $context): void
    {
        $startedAt = microtime(true);
        $tenant = $context->tenant;
        $from = $context->message->from;
        $rawBody = $context->message->messageBody ?? '';
        $body = $this->stripAudioPrefix($rawBody);

        $contact = Contact::firstOrCreate(
            ['tenant_id' => $tenant->id, 'customer_phone' => $from],
            ['summary' => 'Registro de Training', 'bot_active' => true],
        );

        $profile = TrainingProfile::firstOrCreate(
            ['contact_id' => $contact->id],
            ['split_type' => SplitType::FullBody, 'safety_status' => SafetyStatus::Normal],
        );

        // Memoria conversacional: se registra el mensaje entrante una sola
        // vez, sin importar qué subflujo lo termine de atender (Hito 6 —
        // resuelve la deuda de Hito 5).
        $this->logInbound($tenant, $from, $rawBody);

        // 1. Seguridad primero, siempre, sobre el texto crudo del mensaje —
        // antes que cualquier otra cosa, incluso antes de saber si el
        // onboarding está completo. Ver App\Training\Support\SafetySignalDetector.
        $signal = $this->safetyDetector->detect($body);

        if ($signal !== null) {
            $profile->flagForSafetyReview($signal);
            $this->emitSafetyAlert($tenant, $contact, $signal);
            $this->reply($from, SafetySignalDetector::ESCALATION_MESSAGE, $tenant);

            return;
        }

        if ($profile->isFlaggedForSafetyReview()) {
            // Bandera de un turno anterior — sigue bloqueado hasta que un
            // humano la levante (TrainingProfile::clearSafetyFlag()); el LLM
            // nunca decide por sí mismo que ya es seguro continuar.
            $this->reply($from, SafetySignalDetector::ESCALATION_MESSAGE, $tenant);

            return;
        }

        // 2. Onboarding conversacional, mientras falte algún requirement
        // bloqueante. Bloque 4 (ver docs/DECISIONS.md D047):
        // OnboardingRequirementRegistry reemplaza a
        // TrainingProfile::firstMissingOnboardingField()/isOnboardingComplete()
        // como autoridad real — ya NO trata primary_focus/sessions_per_week
        // como bloqueantes (cambio de producto deliberado). Extract+Narrate
        // siguen fusionados en UNA sola llamada de IA (D026, Opción A
        // aprobada) — OnboardingConversationComposer solo aporta un
        // fragmento de prompt adicional, nunca dispara una segunda llamada.
        // Ver App\Training\Onboarding\*.
        // Bloque 9 (D052): si el onboarding se completa DENTRO de este mismo
        // turno, la llamada de IA de onboarding ya fue la única permitida —
        // el paso 5 (Coach) no debe hacer una segunda. Ver más abajo.
        $onboardingJustCompletedThisTurn = false;

        if (! $this->requirementRegistry->isOnboardingComplete($profile, $contact)) {
            // "onboarding_turns": una interacción procesada mientras el
            // onboarding está incompleto — nunca una pregunta individual, ni
            // una llamada de IA. Se incrementa una sola vez por turno, antes
            // de decidir qué preguntar, para que la política de turnos
            // progresivos (secondaryOpportunisticFor()) ya conozca el número
            // de turno correcto de ESTE turno.
            $profile->increment('onboarding_turns');

            $fragment = $this->buildContext($context, 'training_profile');
            $pending = $this->requirementRegistry->firstPendingBlocking($profile, $contact);
            $secondary = $this->requirementRegistry->secondaryOpportunisticFor($profile->onboarding_turns, $profile, $contact);

            $opportunisticInvitation = $secondary !== null
                ? $this->composer->describeOpportunisticInvitation($secondary->questionContext($profile, $contact))
                : null;

            $aiCallStartedAt = microtime(true);
            $result = $this->onboarding->extractAndRespond($body, $fragment->data, $tenant, $pending->key(), $opportunisticInvitation);
            $aiCallElapsedMs = (int) round((microtime(true) - $aiCallStartedAt) * 1000);
            $extracted = $result['extracted'];

            if ($extracted['safety_signal_text'] !== null) {
                $secondSignal = $this->safetyDetector->detect($extracted['safety_signal_text']);

                if ($secondSignal !== null) {
                    $profile->flagForSafetyReview($secondSignal);
                    $this->emitSafetyAlert($tenant, $contact, $secondSignal);
                    $this->reply($from, SafetySignalDetector::ESCALATION_MESSAGE, $tenant);

                    return;
                }
            }

            $this->requirementRegistry->applyExtracted($contact, $profile, $extracted);

            // onAsked() se invoca para lo que realmente se ofreció este
            // turno (principal + secundario/oportunista, si hubo), sin
            // importar si el usuario respondió — necesario para que
            // PhysicalStatsRequirement reproduzca "se pregunta una sola
            // vez, sin importar la respuesta" (ver OnboardingRequirement::onAsked()).
            // No-op para el resto de requirements.
            $pending->onAsked($contact, $profile);
            $secondary?->onAsked($contact, $profile);

            $profile = $profile->fresh();
            $contact = $contact->fresh();

            if (! $this->requirementRegistry->isOnboardingComplete($profile, $contact)) {
                $realMissing = $this->requirementRegistry->firstPendingBlocking($profile, $contact);
                $question = $this->onboarding->resolveQuestion($realMissing->key(), $result['next_action'], $result['response']);

                // Métricas Hito 5.1: comparar contra la línea base de 2
                // llamadas/30-45s — ver docs/DECISIONS.md (D026).
                Log::info('ONBOARDING_TURN_METRICS', [
                    'tenant_id' => $tenant->id,
                    'contact_id' => $contact->id,
                    'onboarding_turn' => $profile->onboarding_turns,
                    'ai_call_elapsed_ms' => $aiCallElapsedMs,
                    'ai_calls_count' => 1,
                    'used_ai_response' => $this->onboarding->usedAiResponse($realMissing->key(), $result['next_action'], $result['response']),
                ]);

                $this->reply($from, $question, $tenant);

                return;
            }

            // El onboarding acababa de estar incompleto y ya no lo está: se
            // completó en este mismo turno, con la única llamada de IA ya
            // consumida por `extractAndRespond()`.
            $onboardingJustCompletedThisTurn = true;
        }

        // 3. Acceso — frontera única hacia el sistema comercial (Hito 4).
        $freshContact = $contact->fresh();
        $gateResult = $this->accessGate->authorize($freshContact);

        if (! $gateResult->allowed) {
            $this->respondToDenial($gateResult->reason, $from, $tenant);

            return;
        }

        // 4. Contexto activo (Bloque 9, D052): si hay una sesión pendiente
        // con ejercicios sin reportar, la ÚNICA llamada de IA de este turno
        // es la de ExecutionReportService (evolucionada) — clasifica en la
        // MISMA llamada si el mensaje es un reporte, una o más
        // interrupciones conversacionales, o ambas a la vez. Esta rama
        // SIEMPRE resuelve el turno por completo — nunca cae al paso 6
        // (jamás reenvía la rutina por una pregunta que no es un reporte).
        $activeSessionFragment = $this->buildContext($context, 'active_workout_session');
        $reportableExercises = $activeSessionFragment->data['unreported_exercises'] ?? [];

        if ($activeSessionFragment->data !== null && $reportableExercises !== [] && $body !== '') {
            $coachContext = $this->buildContext($context, 'coach_context')->data;
            $result = $this->reportExtractor->extractReport($body, $reportableExercises, $tenant, $coachContext);
            $resolved = $this->turnResolver->resolve($result);
            $this->executeTurnActions($resolved, $activeSessionFragment->data, $from, $tenant, $freshContact, $profile, $startedAt, $body);

            return;
        }

        // 5. Sin contexto activo reportable (Bloque 9, D052): CoachService
        // es la ÚNICA llamada de IA de este camino — hoy es el único punto
        // del flujo que no hacía ninguna llamada de IA. `continue_training`
        // dispara determinísticamente la entrega existente (paso 6) sin
        // depender de que la IA produzca o no una respuesta utilizable.
        //
        // Excepción explícita (D026/D052, máximo una llamada de IA por
        // turno): si el onboarding se completó en este mismo turno, esa ya
        // fue la única llamada permitida — se cae directamente al paso 6
        // (comportamiento idéntico al de antes del Bloque 9 para este caso
        // exacto), sin invocar a Coach.
        if ($onboardingJustCompletedThisTurn) {
            // continúa directo al paso 6, sin segunda llamada de IA.
        } elseif ($this->isShortAffirmativeAfterReminder($body, $freshContact)) {
            // Hito 10 (D053) — "sí"/"dale"/... dentro de la ventana de
            // continuidad de un Reminder disparado recientemente se traduce
            // directo a continue_training, cero llamadas de IA. Consume la
            // ventana para no volver a disparar en un mensaje posterior no
            // relacionado.
        } elseif ($body !== '') {
            $coachContext = $this->buildContext($context, 'coach_context')->data;
            $result = $this->coach->respond($body, $coachContext, $tenant);
            // Hito 14 — validación backend en memoria, sin BD adicional: si
            // faq_match_id no pertenece al conjunto de candidatos que
            // realmente se le mostró a la IA este turno (CoachContext ya
            // cargado arriba), se descarta TODA la salida relacionada
            // (incluido cualquier customer_service_message) — degradación
            // totalmente determinista. Ver docs/DECISIONS.md.
            $result = $this->faqMatcher->sanitize($result, $coachContext->activeFaqs ?? []);
            $resolved = $this->turnResolver->resolve($result);
            $shouldDeliverSession = $this->executeTurnActions($resolved, null, $from, $tenant, $freshContact, $profile, $startedAt, $body);

            if (! $shouldDeliverSession) {
                return;
            }
        }

        // 6. Generar y entregar. TrainingEngine vuelve a verificar el Gate
        // internamente (defensa en profundidad) — el catch es un caso límite,
        // no la ruta esperada, dado que ya se verificó arriba.
        $engineStartedAt = microtime(true);

        try {
            $session = $this->engine->decideNextSession($freshContact);
        } catch (TrainingAccessDeniedException $e) {
            $this->respondToDenial($e->reason, $from, $tenant);

            return;
        }

        Log::info('TRAINING_ENGINE_DECIDED', [
            'contact_id' => $freshContact->id,
            'workout_session_id' => $session->id,
            'exercise_count' => $session->workoutExercises->count(),
            'elapsed_ms' => (int) round((microtime(true) - $engineStartedAt) * 1000),
        ]);

        // Hito 9.2: cabecera mínima de sesión — la prescripción y la técnica
        // de cada ejercicio ya van en su propio mensaje (ExerciseMessageFormatter),
        // así que repetirlas aquí sería fragmentación redundante, no menos.
        $this->reply($from, '🔥 Tu entrenamiento de hoy', $tenant);

        foreach ($session->workoutExercises as $index => $workoutExercise) {
            $this->reply($from, $this->messageFormatter->format($workoutExercise, $index + 1), $tenant);

            // Hito 9.1: la URL de video NUNCA viene del snapshot histórico
            // (que puede describir un ejercicio de proveedor sin video_url
            // propio, por diseño) — se resuelve fresca en este mismo
            // instante, vía MediaResolver. Un fallo de resolución (ejercicio
            // borrado, proveedor caído, etc.) omite SOLO el video de este
            // ejercicio — nunca bloquea el resto del mensaje ya enviado.
            $exercise = $workoutExercise->exercise;
            $resolvedMedia = $exercise !== null ? $this->mediaResolver->resolve($exercise) : null;

            if ($resolvedMedia !== null) {
                $videoStartedAt = microtime(true);
                $sent = WhatsAppService::sendWhatsAppVideo(
                    $from,
                    $resolvedMedia->url,
                    $tenant,
                    $workoutExercise->exercise_snapshot['name'] ?? null,
                );

                Log::info($sent ? 'TRAINING_VIDEO_SENT' : 'TRAINING_VIDEO_SEND_FAILED', [
                    'workout_exercise_id' => $workoutExercise->id,
                    'provider' => $exercise->provider,
                    'elapsed_ms' => (int) round((microtime(true) - $videoStartedAt) * 1000),
                ]);
            }
        }
    }

    /**
     * Envoltorio de observabilidad (Hito 7) sobre ContextBuilder::build() para
     * una sola clave — registra qué se recuperó (label/confidence/tamaño
     * aproximado) sin cambiar el mecanismo de Hito 3. Ver docs/DECISIONS.md (D021).
     */
    private function buildContext(ExecutionContext $context, string $key): ContextFragment
    {
        $fragment = $this->contextBuilder->build($context, [$key])[0];

        Log::info('CONTEXT_BUILDER_RESULT', [
            'key' => $key,
            'confidence' => $fragment->confidence,
            'has_data' => $fragment->data !== null,
            'approx_size_bytes' => $fragment->data !== null ? strlen(json_encode($fragment->data)) : 0,
        ]);

        return $fragment;
    }

    /**
     * Bloque 9 (D052) — ejecuta, en orden, la lista de acciones ya resuelta
     * por `ConversationTurnResolver` (nunca decide nada por su cuenta: solo
     * traduce cada `ConversationAction` a su efecto real). `Safety` es el
     * único punto de corte — detiene el resto de acciones del turno.
     * `RecordExecutionReport` se ejecuta y el bucle CONTINÚA (aprobado
     * explícitamente: un mismo mensaje puede combinar un reporte real con
     * una pregunta de otro dominio). Devuelve `true` únicamente si
     * `DeliverSession` estaba entre las acciones — el llamador decide
     * entonces si cae al paso 6 (entrega determinista existente, sin
     * cambios).
     *
     * @param  array{workout_session_id: int, unreported_exercises: array}|null  $activeSessionData
     *         necesario únicamente para `RecordExecutionReport`; `null` en
     *         el camino sin sesión pendiente, donde esa acción nunca aparece.
     */
    private function executeTurnActions(
        ConversationTurnResolved $resolved,
        ?array $activeSessionData,
        string $from,
        Tenant $tenant,
        Contact $contact,
        TrainingProfile $profile,
        float $startedAt,
        string $body = '',
    ): bool {
        $shouldDeliverSession = false;

        foreach ($resolved->actions as $action) {
            if ($action->type === ConversationActionType::EscalateSafety) {
                $profile->flagForSafetyReview($action->safetyReason);
                $this->emitSafetyAlert($tenant, $contact, $action->safetyReason);
                $this->reply($from, SafetySignalDetector::ESCALATION_MESSAGE, $tenant);

                // Safety detiene todo lo demás — nunca se entrega una sesión
                // ni se procesa ningún otro intent de este turno.
                return false;
            }

            if ($action->type === ConversationActionType::RecordExecutionReport && $activeSessionData !== null) {
                $this->recordExecutionReport($action->report, $activeSessionData, $from, $tenant, $contact, $startedAt);

                continue;
            }

            if ($action->type === ConversationActionType::SendText) {
                $this->reply($from, $action->text, $tenant);

                continue;
            }

            if ($action->type === ConversationActionType::ProposeReminder) {
                $this->proposeReminder($action->reminderData, $contact, $tenant, $from);

                continue;
            }

            if ($action->type === ConversationActionType::ApplyReminderDecision) {
                $this->applyReminderDecision($action->reminderData, $contact, $tenant, $from);

                continue;
            }

            if ($action->type === ConversationActionType::OfferProactiveReminder) {
                $this->offerProactiveReminder($action->reminderData['trigger_reason'], $contact, $tenant, $from);

                continue;
            }

            if ($action->type === ConversationActionType::AnswerFaq) {
                // Hito 14 — $action->text ya viene REDACTADO por la IA
                // (grounded en el answer de la FAQ elegida) — nunca se
                // consulta App\CustomerCare\Models\Faq desde aquí.
                $this->reply($from, $action->text, $tenant);

                continue;
            }

            if ($action->type === ConversationActionType::RequestCustomerService) {
                // Hito 14 — CustomerServiceRequest.message es SIEMPRE el
                // mensaje original del usuario, nunca el texto de acuse de
                // recibo que se le responde.
                $this->customerServiceRecorder->record($contact, $body);

                $replyText = $action->text ?? ($action->isFaqFallback
                    ? CustomerServiceRequestRecorder::FAQ_FALLBACK_TEXT
                    : CustomerServiceRequestRecorder::EXPLICIT_REQUEST_TEXT);
                $this->reply($from, $replyText, $tenant);

                continue;
            }

            if ($action->type === ConversationActionType::DeliverSession) {
                if ($activeSessionData !== null) {
                    // Ya existe una sesión pendiente — nunca se genera ni se
                    // reenvía una rutina desde esta rama (D052). Se informa
                    // brevemente en vez de dejar el turno sin respuesta.
                    $this->reply($from, self::PENDING_SESSION_REMINDER, $tenant);

                    continue;
                }

                $shouldDeliverSession = true;
            }
        }

        return $shouldDeliverSession;
    }

    /**
     * @param  array{reports: array, session_finished: bool}  $report
     * @param  array{workout_session_id: int, unreported_exercises: array}  $activeSessionData
     */
    private function recordExecutionReport(array $report, array $activeSessionData, string $from, Tenant $tenant, Contact $contact, float $startedAt): void
    {
        Log::info('TRAINING_REPORT_ATTEMPT', ['workout_session_id' => $activeSessionData['workout_session_id']]);

        $session = WorkoutSession::find($activeSessionData['workout_session_id']);
        $outcome = $this->reportRecorder->record($session, $report);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info($outcome->hasAnyEffect() || $outcome->sessionCompleted ? 'TRAINING_REPORT_SUCCESS' : 'TRAINING_REPORT_INCOMPLETE', [
            'workout_session_id' => $session->id,
            'logged_count' => count($outcome->logged),
            'clarifications_count' => count($outcome->clarifications),
            'session_completed' => $outcome->sessionCompleted,
            'elapsed_ms' => $elapsedMs,
        ]);

        $this->reply($from, $this->buildReportResponseMessage($outcome), $tenant);

        // Hito 10, Trigger 2 de proactividad — determinista, sin IA: la
        // decisión de ofrecer (o no) la toma ReminderProactivityGate, nunca
        // este método por su cuenta.
        if ($outcome->sessionCompleted) {
            $this->offerProactiveReminder(self::PROACTIVE_TRIGGER_SESSION_COMPLETED, $contact, $tenant, $from);
        }
    }

    private function buildReportResponseMessage(ExecutionReportOutcome $outcome): string
    {
        $lines = [];

        if ($outcome->logged !== []) {
            $lines[] = '✅ Registré:';

            foreach ($outcome->logged as $summary) {
                $lines[] = "- {$summary}";
            }
        }

        foreach ($outcome->clarifications as $question) {
            $lines[] = $question;
        }

        if ($outcome->sessionCompleted) {
            $lines[] = '';
            $lines[] = '🏁 Sesión completada. ¡Buen trabajo! Escríbeme cuando quieras tu próximo entrenamiento.';
        }

        return $lines !== [] ? implode("\n", $lines) : 'Listo.';
    }

    // ── Hito 10 — Reminders ──────────────────────────────────────────────

    private const WEEKDAY_INT_TO_STRING = [
        0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday',
    ];

    private const WEEKDAY_SINGULAR = [
        'monday' => 'el lunes', 'tuesday' => 'el martes', 'wednesday' => 'el miércoles', 'thursday' => 'el jueves',
        'friday' => 'el viernes', 'saturday' => 'el sábado', 'sunday' => 'el domingo',
    ];

    private const WEEKDAY_PLURAL = [
        'monday' => 'todos los lunes', 'tuesday' => 'todos los martes', 'wednesday' => 'todos los miércoles',
        'thursday' => 'todos los jueves', 'friday' => 'todos los viernes', 'saturday' => 'todos los sábados',
        'sunday' => 'todos los domingos',
    ];

    /**
     * Hito 10 (D053) — atajo determinista, cero llamadas de IA: una
     * afirmación corta dentro de la ventana de continuidad de un `Reminder`
     * ya disparado se traduce directo a `continue_training`. Consume la
     * ventana al usarla, para que un mensaje posterior no relacionado
     * dentro de las mismas horas no vuelva a dispararla.
     */
    private function isShortAffirmativeAfterReminder(string $body, Contact $contact): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/[.!¡¿?]/u', '', $body) ?? $body));

        if (! in_array($normalized, self::SHORT_AFFIRMATIVE_WORDS, true)) {
            return false;
        }

        $reminder = Reminder::where('contact_id', $contact->id)
            ->whereNotNull('awaiting_response_until')
            ->where('awaiting_response_until', '>', now())
            ->first();

        if ($reminder === null) {
            return false;
        }

        $reminder->update(['awaiting_response_until' => null]);

        return true;
    }

    /**
     * @param  array{day: ?string, time: ?string, recurring: bool}  $data
     */
    private function proposeReminder(array $data, Contact $contact, Tenant $tenant, string $from): void
    {
        if (ReminderSuggestion::activePendingFor($contact) !== null || Reminder::activeFor($contact) !== null) {
            // Ya hay una propuesta pendiente o un recordatorio activo — no
            // se ofrece un segundo en silencio (mismo criterio que el
            // índice único de base de datos).
            return;
        }

        $timezone = $this->timezoneResolver->resolve($contact);
        $resolution = $this->reminderTimeResolver->resolve($data['day'], $data['time'], $data['recurring'], $timezone, now());

        if ($resolution === null) {
            $this->reply($from, self::REMINDER_CLARIFICATION_MESSAGE, $tenant);

            return;
        }

        ReminderSuggestion::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'origin' => ReminderSuggestionOrigin::UserRequest,
            'trigger_reason' => null,
            'proposed_type' => $resolution->recurrence !== null ? 'training_weekly' : 'training_one_off',
            'proposed_params' => $data,
            'status' => ReminderSuggestionStatus::Pending,
            'expires_at' => now()->addHours(24),
        ]);

        $this->reply($from, sprintf(self::REMINDER_PROPOSAL_TEMPLATE, $this->describeDay($data['day'], $data['recurring']), $data['time']), $tenant);
    }

    /**
     * @param  array{decision: string, confirmed: ?bool, day: ?string, time: ?string}  $data
     */
    private function applyReminderDecision(array $data, Contact $contact, Tenant $tenant, string $from): void
    {
        match ($data['decision']) {
            'confirmation' => $this->applyReminderConfirmation($data, $contact, $tenant, $from),
            'cancel' => $this->cancelActiveReminder($contact, $tenant, $from),
            'modify' => $this->modifyActiveReminder($data, $contact, $tenant, $from),
            default => null,
        };
    }

    private function applyReminderConfirmation(array $data, Contact $contact, Tenant $tenant, string $from): void
    {
        $suggestion = ReminderSuggestion::activePendingFor($contact);

        if ($suggestion === null) {
            // Nada pendiente que confirmar — no-op silencioso, mismo
            // criterio que RecordExecutionReport sin sesión activa.
            return;
        }

        if ($data['confirmed'] === false) {
            $suggestion->update(['status' => ReminderSuggestionStatus::Declined]);
            $this->reply($from, self::REMINDER_DECLINED_MESSAGE, $tenant);

            return;
        }

        if (Reminder::activeFor($contact) !== null) {
            $this->reply($from, self::REMINDER_ALREADY_ACTIVE_MESSAGE, $tenant);

            return;
        }

        // Override ("Sí, pero a las 8"): CÓDIGO revalida con el mismo
        // resolver — nunca se acepta el valor de la IA a ciegas, ni
        // siquiera en una confirmación.
        $params = $suggestion->proposed_params;
        $day = $data['day'] ?? $params['day'];
        $time = $data['time'] ?? $params['time'];
        $recurring = $params['recurring'];

        $timezone = $this->timezoneResolver->resolve($contact);
        $resolution = $this->reminderTimeResolver->resolve($day, $time, $recurring, $timezone, now());

        if ($resolution === null) {
            // La suggestion sigue pending — no se pierde la aceptación
            // implícita, se pide precisar el dato que falta.
            $this->reply($from, self::REMINDER_CLARIFICATION_MESSAGE, $tenant);

            return;
        }

        Reminder::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'type' => $suggestion->proposed_type,
            'status' => \App\Training\Enums\ReminderStatus::Pending,
            'fire_at' => $resolution->fireAt,
            'recurrence' => $resolution->recurrence,
            'created_from_suggestion_id' => $suggestion->id,
        ]);

        $suggestion->update(['status' => ReminderSuggestionStatus::Accepted]);

        $this->reply($from, sprintf(self::REMINDER_CONFIRMED_MESSAGE, $this->describeDay($day, $recurring), $time), $tenant);
    }

    private function cancelActiveReminder(Contact $contact, Tenant $tenant, string $from): void
    {
        $reminder = Reminder::activeFor($contact);

        if ($reminder === null) {
            $this->reply($from, self::REMINDER_NONE_ACTIVE_MESSAGE, $tenant);

            return;
        }

        $reminder->update(['status' => \App\Training\Enums\ReminderStatus::Cancelled, 'cancelled_at' => now()]);
        $this->reply($from, self::REMINDER_CANCELLED_MESSAGE, $tenant);
    }

    /**
     * @param  array{day: ?string, time: ?string}  $data
     */
    private function modifyActiveReminder(array $data, Contact $contact, Tenant $tenant, string $from): void
    {
        $reminder = Reminder::activeFor($contact);

        if ($reminder === null) {
            $this->reply($from, self::REMINDER_NONE_ACTIVE_MESSAGE, $tenant);

            return;
        }

        $timezone = $this->timezoneResolver->resolve($contact);
        $currentLocal = \Carbon\CarbonImmutable::instance($reminder->fire_at)->setTimezone($timezone);
        $recurring = $reminder->recurrence !== null;

        // Sin día nuevo explícito ("cámbialo para las 8"): se conserva el
        // día que ya tenía la ocurrencia actual — nunca se adivina uno
        // distinto.
        $day = $data['day'] ?? self::WEEKDAY_INT_TO_STRING[$currentLocal->dayOfWeek];
        $time = $data['time'] ?? $currentLocal->format('H:i');

        $resolution = $this->reminderTimeResolver->resolve($day, $time, $recurring, $timezone, now());

        if ($resolution === null) {
            $this->reply($from, self::REMINDER_CLARIFICATION_MESSAGE, $tenant);

            return;
        }

        $reminder->update(['fire_at' => $resolution->fireAt, 'recurrence' => $resolution->recurrence]);

        $this->reply($from, sprintf(self::REMINDER_MODIFIED_MESSAGE, $this->describeDay($day, $recurring), $time), $tenant);
    }

    /**
     * Hito 10 (D053, corrección post-revisión) — los 3 triggers MVP de
     * proactividad ("terminó una sesión" / "menciona que se le olvida
     * entrenar" / "pregunta cuándo debería entrenar") convergen aquí: la
     * ÚNICA decisión de si corresponde ofrecer la toma `ReminderProactivityGate`
     * — código, nunca la IA — con los MISMOS controles anti-spam
     * (cooldown proactivo, cooldown post-rechazo) sin importar cuál de los
     * 3 disparó la llamada. `$triggerReason` solo se persiste para
     * auditoría/análisis posterior — nunca cambia la política de oferta.
     * Texto y parámetros deterministas: la hora (`PROACTIVE_OFFER_TIME`) es
     * un valor técnico provisional, marcado explícitamente como decisión
     * de negocio pendiente. Nunca crea un `Reminder` — solo una
     * `ReminderSuggestion` pendiente, que sigue exigiendo confirmación
     * explícita como cualquier otra.
     */
    private function offerProactiveReminder(string $triggerReason, Contact $contact, Tenant $tenant, string $from): void
    {
        if (! $this->proactivityGate->canOffer($contact)) {
            return;
        }

        $timezone = $this->timezoneResolver->resolve($contact);
        $day = self::WEEKDAY_INT_TO_STRING[\Carbon\CarbonImmutable::now($timezone)->dayOfWeek];

        $resolution = $this->reminderTimeResolver->resolve($day, self::PROACTIVE_OFFER_TIME, true, $timezone, now());

        if ($resolution === null) {
            return;
        }

        ReminderSuggestion::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'origin' => ReminderSuggestionOrigin::Proactive,
            'trigger_reason' => $triggerReason,
            'proposed_type' => 'training_weekly',
            'proposed_params' => ['day' => $day, 'time' => self::PROACTIVE_OFFER_TIME, 'recurring' => true],
            'status' => ReminderSuggestionStatus::Pending,
            'expires_at' => now()->addHours(24),
        ]);

        $this->reply(
            $from,
            sprintf('¿Quieres que te recuerde entrenar %s a las %s? Responde "sí" para confirmar. 💪', $this->describeDay($day, true), self::PROACTIVE_OFFER_TIME),
            $tenant,
        );
    }

    private function describeDay(?string $day, bool $recurring): string
    {
        if ($day === 'tomorrow') {
            return 'mañana';
        }

        if ($day === 'today') {
            return 'hoy';
        }

        $map = $recurring ? self::WEEKDAY_PLURAL : self::WEEKDAY_SINGULAR;

        return $map[$day] ?? 'ese día';
    }

    private function respondToDenial(?string $reason, string $from, Tenant $tenant): void
    {
        $message = match ($reason) {
            'safety_flagged' => SafetySignalDetector::ESCALATION_MESSAGE,
            // Bloque 5: mensaje propio, distinto del de emergencia y del de
            // "activa tu acceso" — nunca hace afirmaciones médicas, solo
            // informa que hay una revisión humana en curso. Ver
            // TrainingAccessGate::authorize() y docs/DECISIONS.md D048.
            'health_screening_pending' => self::HEALTH_SCREENING_PENDING_MESSAGE,
            default => self::ACCESS_REQUIRED_MESSAGE,
        };

        $this->reply($from, $message, $tenant);
    }

    private function stripAudioPrefix(string $body): string
    {
        return preg_replace('/^🎤\s*\[AUDIO\]:\s*/u', '', $body) ?? $body;
    }

    /**
     * Memoria conversacional (Hito 6): registra el mensaje entrante del
     * usuario en WhatsAppMessage, sin importar qué subflujo lo procese
     * después — separado por completo de ExerciseLog/ExerciseSet, que
     * representan hechos de entrenamiento, no conversación.
     */
    private function logInbound(Tenant $tenant, string $from, string $rawBody): void
    {
        WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $from,
            'role' => 'user',
            'content' => $rawBody,
        ]);
    }

    /**
     * Envía Y persiste una respuesta saliente — todo mensaje de Training
     * hacia el usuario pasa por aquí, para que quede en WhatsAppMessage
     * igual que ya ocurre en App\Handlers\FallbackChatHandler.
     */
    private function reply(string $from, string $text, Tenant $tenant): void
    {
        $message = WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $from,
            'role' => 'assistant',
            'content' => $text,
        ]);

        $wamid = WhatsAppService::sendMessage($from, $text, $tenant);

        if ($wamid) {
            WhatsAppStatusTracker::trackMessage($message->id, $wamid);
        } else {
            // WhatsAppService ya registra el detalle del error de Meta — este
            // log adicional (Hito 7) permite contar "errores Meta" del flujo
            // de Training específicamente, sin duplicar el detalle.
            Log::warning('TRAINING_META_SEND_FAILED', [
                'whatsapp_message_id' => $message->id,
                'tenant_id' => $tenant->id,
                'customer_phone' => $from,
            ]);
        }
    }

    /**
     * Hito 7.1: emite la Alert hacia AlertService (Core) — este método NO
     * decide el canal ni el destinatario, solo describe qué pasó. Envuelto
     * en su propio try/catch como defensa adicional (AlertService ya
     * garantiza internamente que un canal roto no se propaga, pero el
     * bloqueo de seguridad de este Handler debe seguir funcionando incluso
     * si AlertService fallara de una forma totalmente inesperada) — nunca
     * debe impedir que se responda con el mensaje de escalamiento.
     */
    private function emitSafetyAlert(Tenant $tenant, Contact $contact, string $reason): void
    {
        try {
            $this->alerts->send(new Alert(
                category: 'safety',
                severity: AlertSeverity::Critical,
                message: "Señal de seguridad detectada ({$reason}) — perfil marcado flagged_for_review.",
                context: [
                    'tenant_id' => $tenant->id,
                    'contact_id' => $contact->id,
                    'customer_phone' => $contact->customer_phone,
                    'reason' => $reason,
                ],
            ));
        } catch (\Throwable $e) {
            Log::error('SAFETY_ALERT_EMIT_FAILED', [
                'tenant_id' => $tenant->id,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
