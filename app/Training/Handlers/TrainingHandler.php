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
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WhatsAppMessage;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\ConversationActionType;
use App\Training\Enums\ReminderSuggestionOrigin;
use App\Training\Enums\ReminderSuggestionStatus;
use App\Training\Enums\SafetyStatus;
use App\Training\Enums\SessionCloseIntent;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingAccessStatus;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Onboarding\OnboardingConversationComposer;
use App\Training\Onboarding\OnboardingRequirementRegistry;
use App\Training\Support\AutomaticTrialProvisioner;
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
use App\Training\Support\SessionCloseMessageComposer;
use App\Training\Support\TimezoneResolver;
use App\Training\Support\TrainingAccessDeniedException;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrialEndedMessageComposer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The Training conversational flow (Hito 5, extended en Hito 6, Bloque 9):
 *
 *   ¿señal de seguridad?         -> escalar y detener
 *   ¿perfil incompleto?          -> onboarding conversacional (Extract -> Decide -> Narrate)
 *   ¿sin acceso ('no_access')?   -> Hito 15: AutomaticTrialProvisioner intenta un Trial
 *                                    automático (elegible = nunca tuvo Trial Y nunca tuvo
 *                                    un Payment confirmado); si no es elegible, o el motivo
 *                                    de denegación es otro (acceso histórico vencido/
 *                                    revocado, 'access_invalid'), se informa que debe
 *                                    activarse el servicio, sin cambios
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
     * Hito 15.1 (Ronda 2, Cambio 2) — se envía UNA sola vez, en el mismo
     * turno en que `AutomaticTrialProvisioner::provisionIfEligible()`
     * concede el Trial por primera vez — nunca porque un Trial ya vigente
     * exista (eso sería reenviarlo en cada turno posterior). Usa
     * EXCLUSIVAMENTE datos reales ya disponibles en el `TrainingAccess`
     * recién creado y en `Tenant::trial_duration_days` — determinista, sin
     * IA, sin prometer ninguna notificación de vencimiento (no existe
     * ningún mecanismo de ese tipo hoy — ver docs/DECISIONS.md).
     */
    private const TRIAL_GRANTED_MESSAGE = '🎁 Te activé tu período de prueba gratis: %d días, válidos hasta el %s. '
        .'Durante este tiempo puedes entrenar con tu plan personalizado sin costo. Cuando termine, si quieres '
        .'seguir, puedes escribirme para conocer la membresía.';

    /**
     * H16.1 (Cambio 1) — fallback determinista, usado cuando la IA de
     * onboarding no produjo una redacción válida para el turno de
     * finalización (ver `OnboardingConversationService::resolveProfileReadyMessage()`).
     * Deliberadamente NO promete que la rutina llega de inmediato — un
     * intento de Trial automático inelegible en el mismo turno puede
     * terminar en un mensaje de acceso requerido en vez de una rutina.
     */
    private const PROFILE_READY_FALLBACK_MESSAGE = 'Con esto ya tengo lo que necesito para armar tu plan.';

    /**
     * H16.2 Fase 1 — entrega progresiva: transición determinista (sin IA,
     * ver docblock de `recordExecutionReport()`) enviada antes del siguiente
     * ejercicio, únicamente tras un reporte real ya persistido de uno
     * anterior. Rotación simple por `WorkoutExercise.order` — evita repetir
     * literalmente la misma frase entre el ejercicio 2 y el 3 de una misma
     * sesión, sin necesitar IA para algo tan acotado.
     */
    private const EXERCISE_ADVANCE_MESSAGES = [
        '¡Bien! 💪 Vamos con el siguiente.',
        'Perfecto, sigamos.',
        '¡Anotado! Vamos con el que sigue.',
    ];

    /**
     * H16.2 Fase 1 — cierre implícito (el usuario completó el último
     * ejercicio pendiente SIN decir "ya terminé"/equivalente): determinista,
     * sin IA (ver regla "nunca una segunda llamada por un reporte normal").
     * Mismo copy que la constante que reemplaza (Hito 9.2).
     */
    private const SESSION_COMPLETED_IMPLICIT_MESSAGE = '🏁 Sesión completada. ¡Buen trabajo! Escríbeme cuando quieras tu próximo entrenamiento.';

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
        private readonly AutomaticTrialProvisioner $trialProvisioner,
        private readonly TrialEndedMessageComposer $trialEndedComposer,
        private readonly SessionCloseMessageComposer $sessionCloseComposer,
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
        // H16.1 (Cambio 1) — calculado AQUÍ (si aplica) pero enviado más
        // abajo, después de resolver el paso 3: si en este mismo turno se
        // concede un Trial automático, ese aviso ya cumple la función de
        // "perfil listo" (ver más abajo) — se decide recién entonces.
        $profileReadyMessage = null;

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

            // H16.1 (Cambio 1) — reutiliza la MISMA llamada de arriba, sin
            // ninguna llamada de IA adicional. El fallback determinista se
            // resuelve aquí mismo para que, más abajo, un simple `!== null`
            // baste para decidir si corresponde enviarlo.
            $profileReadyMessage = $this->onboarding->resolveProfileReadyMessage($result['next_action'], $result['response'])
                ?? self::PROFILE_READY_FALLBACK_MESSAGE;
        }

        // 3. Acceso — frontera única hacia el sistema comercial (Hito 4).
        $freshContact = $contact->fresh();
        $gateResult = $this->accessGate->authorize($freshContact);

        // Hito 15 — Trial automático: se intenta ÚNICAMENTE cuando el
        // motivo de denegación es 'no_access' (ausencia total de fila
        // TrainingAccess) — un acceso histórico vencido/revocado cae en
        // 'access_invalid', nunca dispara este mecanismo (ver docblock de
        // AutomaticTrialProvisioner). Si el Contact no es elegible
        // (ya tuvo Trial, o ya tuvo un Payment confirmado alguna vez),
        // provisionIfEligible() devuelve null y el turno sigue exactamente
        // igual que antes de este hito.
        if (! $gateResult->allowed && $gateResult->reason === 'no_access') {
            $grantedTrial = $this->trialProvisioner->provisionIfEligible($freshContact);

            if ($grantedTrial !== null) {
                // Se envía AQUÍ, no diferido a la entrega del paso 6: este
                // turno puede no llegar nunca a generar una rutina (ej. el
                // mensaje que disparó la concesión no pedía entrenar) y
                // trial_granted_at es inmutable — este es el único punto
                // que garantiza el aviso exactamente una vez, en el turno
                // real de la concesión.
                $this->sendTrialGrantedNotice($from, $tenant, $grantedTrial);
                // H16.1 (Cambio 1, orden de mensajes) — el aviso de Trial ya
                // cumple la función de "perfil listo" (confirma que el
                // perfil está completo y que hay acceso) — enviar ambos
                // sería redundante. Se omite únicamente en este caso.
                $profileReadyMessage = null;
                $freshContact = $freshContact->fresh();
                $gateResult = $this->accessGate->authorize($freshContact);
            }
        }

        // H16.1 (Cambio 1) — se envía aquí, después de resolver el intento
        // de Trial, sin importar si el acceso terminó permitido o denegado:
        // "perfil listo" describe la completitud del PERFIL, no el acceso —
        // sigue siendo cierto incluso si el turno termina en un mensaje de
        // acceso requerido a continuación.
        if ($profileReadyMessage !== null) {
            $this->reply($from, $profileReadyMessage, $tenant);
        }

        if (! $gateResult->allowed) {
            $this->respondToDenial($gateResult->reason, $freshContact, $from, $tenant);

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

            // H16.1 (Cambio 3) — se marca ÚNICAMENTE cuando el contrato JSON
            // confirma explícitamente que el refuerzo se incorporó Y ese
            // texto realmente se va a enviar (una acción SendText resuelta
            // para él) — nunca por el solo hecho de haber invocado a
            // CoachService, ni por que el campo llegara en true sin que se
            // hubiera pedido este turno (needsConversationReinforcement).
            if ($coachContext->needsConversationReinforcement
                && $result['conversation_reinforcement_included']
                && collect($resolved->actions)->contains(fn ($action) => $action->type === ConversationActionType::SendText)) {
                $profile->update(['coach_conversation_reinforced' => true]);
            }

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
            $this->respondToDenial($e->reason, $freshContact, $from, $tenant);

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

        // H16.2 Fase 1 — entrega progresiva: se entrega ÚNICAMENTE el primer
        // ejercicio (order más bajo — ya garantizado por
        // WorkoutSession::workoutExercises(), ver Modelo) — nunca los N de
        // una sola vez. El resto se entrega uno a la vez, solo tras un
        // reporte real del anterior (ver recordExecutionReport()) — sin
        // ningún estado nuevo persistido: "el siguiente" siempre se deriva
        // de los WorkoutExercise sin ExerciseLog, ya ordenados por columna.
        // Guard defensivo (ya existía implícitamente en el bucle anterior,
        // que simplemente no iteraba nada): una sesión sin ningún ejercicio
        // elegible (catálogo vacío/sin match) no debe romper el turno.
        $firstExercise = $session->workoutExercises->first();

        if ($firstExercise !== null) {
            $this->deliverExercise($firstExercise, $from, $tenant);
        }
    }

    /**
     * H16.2 Fase 1 — entrega UN ejercicio (técnica + video) como unidad,
     * reutilizada tanto para el primero de una sesión (paso 6) como para
     * cada avance tras un reporte real (`recordExecutionReport()`).
     * Extraído sin cambios de comportamiento respecto al bucle que
     * reemplaza — mismos mecanismos (`ExerciseMessageFormatter`/
     * `MediaResolver`), ahora aplicados a un solo `WorkoutExercise` por
     * llamada. Numera con `WorkoutExercise->order` (columna real, ya
     * ordenada) en vez de un índice de bucle — el número mostrado es
     * siempre correcto sin importar si se entrega el primero o se avanza.
     */
    private function deliverExercise(WorkoutExercise $workoutExercise, string $from, Tenant $tenant): void
    {
        $this->reply($from, $this->messageFormatter->format($workoutExercise, $workoutExercise->order), $tenant);

        // Hito 9.1: la URL de video NUNCA viene del snapshot histórico
        // (que puede describir un ejercicio de proveedor sin video_url
        // propio, por diseño) — se resuelve fresca en este mismo
        // instante, vía MediaResolver. Un fallo de resolución (ejercicio
        // borrado, proveedor caído, etc.) omite SOLO el video de este
        // ejercicio — nunca bloquea el resto del mensaje ya enviado.
        $exercise = $workoutExercise->exercise;
        $resolvedMedia = $exercise !== null ? $this->mediaResolver->resolve($exercise) : null;

        if ($resolvedMedia === null) {
            return;
        }

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
     * H16.2 Fase 1 — regla "código decide, IA redacta" aplicada al cierre de
     * sesión: la SEGUNDA llamada de IA de este turno (`SessionCloseMessageComposer`)
     * ocurre ÚNICA Y EXCLUSIVAMENTE cuando `$report['session_finished']` es
     * `true` — un intento EXPLÍCITO de cierre ("ya terminé"/equivalente),
     * ya calculado por la extracción de `ExecutionReportService` (la única
     * llamada de IA de este turno hasta este punto). Un reporte normal
     * (`session_finished=false`) NUNCA dispara esta segunda llamada, ni
     * siquiera cuando ese mismo reporte completa la sesión de forma
     * implícita (ese caso usa `SESSION_COMPLETED_IMPLICIT_MESSAGE`, sin IA).
     *
     * `SessionCloseIntent` se calcula AQUÍ, en código, DESPUÉS de que
     * `ExecutionReportRecorder::record()` ya decidió el estado real — el
     * composer solo recibe la intención y los hechos ya resueltos, nunca
     * decide si la sesión está completa ni qué falta.
     *
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

        // Estado fresco, necesario tanto para decidir el próximo ejercicio
        // como para calcular SessionCloseIntent — exerciseSets se necesita
        // para distinguir Performed de Skipped (mismo criterio ya usado en
        // CoachContextProvider/TrainingHistoryContextProvider).
        $session->load(['workoutExercises.exerciseLog.exerciseSets']);
        $stillUnreported = $session->workoutExercises->filter(fn (WorkoutExercise $we) => $we->exerciseLog === null)->values();

        $explicitCloseAttempt = ($report['session_finished'] ?? false) === true;

        if ($explicitCloseAttempt) {
            $intent = $this->determineSessionCloseIntent($outcome, $session);
            $facts = $this->buildSessionCloseFacts($intent, $outcome, $stillUnreported, $session, $contact);
            $this->reply($from, $this->sessionCloseComposer->compose($intent, $facts, $tenant), $tenant);
        } else {
            // H16.2 Fase 1.2 — confirmación + transición van en UN solo
            // mensaje (nunca dos `reply()` separados) para que se sienta
            // como una única intervención del coach, no como un recibo
            // seguido de un mensaje administrativo aparte.
            $this->reply($from, $this->buildReportResponseMessage($outcome, $stillUnreported), $tenant);

            // H16.2 Fase 1 — avanzar al siguiente ejercicio: ÚNICAMENTE tras
            // un reporte REAL ya persistido este turno (`logged !== []`) —
            // una pregunta sola, o un intento que no logró resolverse a
            // ningún ejercicio (solo `clarifications`), NUNCA avanza ni
            // reenvía el ejercicio actual.
            if ($outcome->logged !== [] && $outcome->clarifications === [] && ! $outcome->sessionCompleted && $stillUnreported->isNotEmpty()) {
                $next = $stillUnreported->first();

                Log::info('TRAINING_EXERCISE_ADVANCED', [
                    'workout_session_id' => $session->id,
                    'workout_exercise_id' => $next->id,
                    'order' => $next->order,
                ]);

                $this->deliverExercise($next, $from, $tenant);
            }
        }

        // Hito 10, Trigger 2 de proactividad — determinista, sin IA: la
        // decisión de ofrecer (o no) la toma ReminderProactivityGate, nunca
        // este método por su cuenta.
        if ($outcome->sessionCompleted) {
            $this->offerProactiveReminder(self::PROACTIVE_TRIGGER_SESSION_COMPLETED, $contact, $tenant, $from);
        }
    }

    /**
     * H16.2 Fase 1.2 — UN solo mensaje determinista para el camino SIN
     * intento explícito de cierre (reporte normal): confirmación + (cierre
     * implícito o transición al siguiente), nunca fragmentado en varios
     * `reply()`. Nunca puede coexistir un cierre afirmado con pendientes
     * reales: `sessionCompleted` ya no puede ser `true` mientras exista un
     * ejercicio sin `ExerciseLog` (fix de
     * `ExecutionReportRecorder::maybeCompleteSession()`).
     *
     * @param  Collection<int, WorkoutExercise>  $stillUnreported
     */
    private function buildReportResponseMessage(ExecutionReportOutcome $outcome, Collection $stillUnreported): string
    {
        $sentences = [];

        if ($outcome->logged !== []) {
            $sentences[] = $this->humanizedConfirmation($outcome->logged);
        }

        foreach ($outcome->clarifications as $question) {
            $sentences[] = $question;
        }

        if ($outcome->sessionCompleted) {
            $sentences[] = self::SESSION_COMPLETED_IMPLICIT_MESSAGE;
        } elseif ($outcome->logged !== [] && $outcome->clarifications === [] && $stillUnreported->isNotEmpty()) {
            // Misma frase de transición determinista de Fase 1 — ahora
            // enlazada en el MISMO mensaje que la confirmación, en vez de un
            // segundo `reply()` aparte.
            $sentences[] = $this->exerciseAdvanceTransition($stillUnreported->first());
        }

        return $sentences !== [] ? implode(' ', $sentences) : 'Listo.';
    }

    /**
     * H16.2 Fase 1.2 — lenguaje natural, nunca notación técnica: envuelve
     * cada frase ya humanizada por `ExecutionReportRecorder::summaryOf()`
     * (que nunca incluye verbo de apertura) con un único verbo neutro
     * ("Registré...") que funciona igual de bien para lo realizado, lo no
     * realizado, o un reporte sin datos cuantificables — nunca celebra
     * automáticamente un "no realizado" (punto 11 de la especificación).
     */
    private function humanizedConfirmation(array $loggedSummaries): string
    {
        if (count($loggedSummaries) === 1) {
            return "Registré {$loggedSummaries[0]}.";
        }

        return 'Registré: '.implode('; ', $loggedSummaries).'.';
    }

    /**
     * H16.2 Fase 1 — código, nunca la IA: calcula la intención de cierre
     * DESPUÉS de que `ExecutionReportRecorder::record()` ya decidió el
     * estado real. Mismo criterio de 3 vías ya usado en
     * `CoachContextProvider`/`TrainingHistoryContextProvider` para
     * distinguir Performed de Skipped — ningún estado nuevo persistido.
     */
    private function determineSessionCloseIntent(ExecutionReportOutcome $outcome, WorkoutSession $session): SessionCloseIntent
    {
        if (! $outcome->sessionCompleted) {
            // Con el fix de maybeCompleteSession(), sessionCompleted=false
            // en un intento explícito de cierre implica, por construcción,
            // que $stillUnreported no está vacío.
            return SessionCloseIntent::BlockedStillPending;
        }

        $hasSkipped = $session->workoutExercises->contains(
            fn (WorkoutExercise $we) => $we->exerciseLog !== null && $we->exerciseLog->exerciseSets->isEmpty()
        );

        return $hasSkipped ? SessionCloseIntent::SuccessPartial : SessionCloseIntent::SuccessFull;
    }

    /**
     * @param  Collection<int, WorkoutExercise>  $stillUnreported
     * @return array{intent: string, contact_name: ?string, pending_exercises: string[], next_exercise: ?string, logged_summaries: string[], skipped_exercise_names: string[]}
     */
    private function buildSessionCloseFacts(SessionCloseIntent $intent, ExecutionReportOutcome $outcome, Collection $stillUnreported, WorkoutSession $session, Contact $contact): array
    {
        // `skipped_exercise_names` es de uso EXCLUSIVO del fallback
        // determinista de SessionCloseMessageComposer — nunca se expone en
        // el bloque de HECHOS del prompt; `logged_summaries` ya comunica en
        // lenguaje natural qué quedó sin realizar (ver
        // ExecutionReportRecorder::summaryOf(), H16.2 Fase 1.2).
        $skippedNames = $session->workoutExercises
            ->filter(fn (WorkoutExercise $we) => $we->exerciseLog !== null && $we->exerciseLog->exerciseSets->isEmpty())
            ->map(fn (WorkoutExercise $we) => $we->exercise_snapshot['name'] ?? 'ese ejercicio')
            ->values()
            ->all();

        $pendingNames = $stillUnreported->map(fn (WorkoutExercise $we) => $we->exercise_snapshot['name'] ?? 'ese ejercicio')->values()->all();

        return [
            'intent' => $intent->value,
            'contact_name' => $contact->customer_name,
            'pending_exercises' => $pendingNames,
            'next_exercise' => $pendingNames[0] ?? null,
            'logged_summaries' => $outcome->logged,
            'skipped_exercise_names' => $skippedNames,
        ];
    }

    /**
     * H16.2 Fase 1 — determinista, sin IA (regla explícita: nunca una
     * segunda llamada por un reporte normal). Rotación simple para no
     * repetir literalmente la misma frase entre ejercicios consecutivos de
     * una misma sesión.
     */
    private function exerciseAdvanceTransition(WorkoutExercise $next): string
    {
        $index = ($next->order - 1) % count(self::EXERCISE_ADVANCE_MESSAGES);

        return self::EXERCISE_ADVANCE_MESSAGES[$index];
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

    /**
     * Hito 15.1 (Cambio 2) — datos EXCLUSIVAMENTE del `TrainingAccess` ya
     * concedido (`expires_at`) y del `Tenant` (`trial_duration_days`) —
     * nunca recalculados ni inventados aquí. Formato de fecha numérico
     * (d/m/Y), independiente de locale.
     */
    private function sendTrialGrantedNotice(string $from, Tenant $tenant, TrainingAccess $grantedTrial): void
    {
        $message = sprintf(
            self::TRIAL_GRANTED_MESSAGE,
            $tenant->trial_duration_days,
            $grantedTrial->expires_at->format('d/m/Y'),
        );

        $this->reply($from, $message, $tenant);
    }

    private function respondToDenial(?string $reason, Contact $contact, string $from, Tenant $tenant): void
    {
        $message = match ($reason) {
            'safety_flagged' => SafetySignalDetector::ESCALATION_MESSAGE,
            // Bloque 5: mensaje propio, distinto del de emergencia y del de
            // "activa tu acceso" — nunca hace afirmaciones médicas, solo
            // informa que hay una revisión humana en curso. Ver
            // TrainingAccessGate::authorize() y docs/DECISIONS.md D048.
            'health_screening_pending' => self::HEALTH_SCREENING_PENDING_MESSAGE,
            default => $this->resolveAccessDeniedMessage($contact, $tenant),
        };

        $this->reply($from, $message, $tenant);
    }

    /**
     * H16.1 (Cambio 4) — diferencia el mensaje según el estado REAL del
     * acceso ('no_access'/'access_invalid', los dos motivos que caen aquí),
     * en vez del mensaje genérico único de antes. `TrainingAccessGate` sigue
     * siendo la única autoridad de SI se deniega, sin cambios — este método
     * solo decide QUÉ texto corresponde, leyendo datos ya persistidos.
     */
    private function resolveAccessDeniedMessage(Contact $contact, Tenant $tenant): string
    {
        $access = $contact->trainingAccess;

        if ($access === null) {
            return self::ACCESS_REQUIRED_MESSAGE;
        }

        if ($access->status === TrainingAccessStatus::Revoked) {
            return $this->trialEndedComposer->compose(
                ['access_state' => 'revoked', 'completed_sessions_count' => null],
                $tenant,
            );
        }

        if ($access->status === TrainingAccessStatus::Trial) {
            $completedSessions = WorkoutSession::where('contact_id', $contact->id)
                ->where('status', WorkoutSessionStatus::Completed)
                ->count();

            if ($completedSessions === 0) {
                // Mismo mensaje que "nunca tuvo acceso" — un Trial vencido
                // sin ninguna sesión completada no tiene ningún hecho real
                // que citar, sería contraproducente inventar una variante.
                return self::ACCESS_REQUIRED_MESSAGE;
            }

            return $this->trialEndedComposer->compose(
                ['access_state' => 'trial_expired', 'completed_sessions_count' => $completedSessions],
                $tenant,
            );
        }

        // Active/Free vencidos — únicos estados restantes que
        // TrainingAccessGate deniega con 'access_invalid'.
        return $this->trialEndedComposer->compose(
            ['access_state' => 'paid_expired', 'completed_sessions_count' => null],
            $tenant,
        );
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
