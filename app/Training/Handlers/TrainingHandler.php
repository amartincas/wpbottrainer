<?php

namespace App\Training\Handlers;

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertService;
use App\Core\Alerts\AlertSeverity;
use App\Core\Memory\ContextBuilder;
use App\Core\Memory\ContextFragment;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\HandlerInterface;
use App\ExerciseCatalog\MediaResolver;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrainingProfile;
use App\Models\WhatsAppMessage;
use App\Models\WorkoutSession;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\ConversationActionType;
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
use App\Training\Support\SafetySignalDetector;
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
            $this->executeTurnActions($resolved, $activeSessionFragment->data, $from, $tenant, $freshContact, $profile, $startedAt);

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
        } elseif ($body !== '') {
            $coachContext = $this->buildContext($context, 'coach_context')->data;
            $result = $this->coach->respond($body, $coachContext, $tenant);
            $resolved = $this->turnResolver->resolve($result);
            $shouldDeliverSession = $this->executeTurnActions($resolved, null, $from, $tenant, $freshContact, $profile, $startedAt);

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
                $this->recordExecutionReport($action->report, $activeSessionData, $from, $tenant, $startedAt);

                continue;
            }

            if ($action->type === ConversationActionType::SendText) {
                $this->reply($from, $action->text, $tenant);

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
    private function recordExecutionReport(array $report, array $activeSessionData, string $from, Tenant $tenant, float $startedAt): void
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
