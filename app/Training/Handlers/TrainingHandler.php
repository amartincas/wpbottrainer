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
use App\Training\Enums\SafetyStatus;
use App\Training\Enums\SplitType;
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
 * The Training conversational flow (Hito 5, extended in Hito 6):
 *
 *   ¿señal de seguridad?       -> escalar y detener
 *   ¿perfil incompleto?         -> onboarding conversacional (Extract -> Decide -> Narrate)
 *   ¿sin acceso?                 -> informar que debe activarse el servicio
 *   ¿sesión activa con reporte?  -> registrar ejecución (Hito 6) -> confirmar/preguntar/cerrar sesión
 *   en otro caso                  -> TrainingEngine decide la sesión -> se entrega + video(s)
 *
 * Orchestration only: every real decision is delegated to a domain service
 * (SafetySignalDetector, OnboardingConversationService, TrainingAccessGate,
 * TrainingEngine, ExecutionReportService, ExecutionReportRecorder) — this
 * class does not contain business rules of its own. Stateless: tenant/
 * contact/message data are local variables, never instance properties.
 *
 * No hay un Intent nuevo para "reportar ejecución" — el Router (Hito 5)
 * sigue clasificando únicamente `training`; este subflujo se resuelve aquí
 * dentro, como pidió explícitamente el Hito 6.
 *
 * Memoria conversacional (Hito 6): toda entrada y salida relevante se
 * persiste en WhatsAppMessage (ver logInbound()/reply()) — separada de
 * ExerciseLog/ExerciseSet, que representan hechos de entrenamiento, no
 * conversación. Ninguna de las dos fuentes sustituye a la otra.
 */
class TrainingHandler implements HandlerInterface
{
    private const ACCESS_REQUIRED_MESSAGE = 'Tu perfil ya está listo. 💪 Para comenzar a entrenar necesitas activar '
        .'tu acceso. Escribe "quiero pagar" para ver las opciones.';

    public function __construct(
        private readonly TrainingAccessGate $accessGate,
        private readonly TrainingEngine $engine,
        private readonly SafetySignalDetector $safetyDetector,
        private readonly OnboardingConversationService $onboarding,
        private readonly ExecutionReportService $reportExtractor,
        private readonly ExecutionReportRecorder $reportRecorder,
        private readonly ContextBuilder $contextBuilder,
        private readonly AlertService $alerts,
        private readonly MediaResolver $mediaResolver,
        private readonly ExerciseMessageFormatter $messageFormatter,
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

        // 2. Onboarding conversacional, mientras falte algún dato obligatorio.
        // Hito 5.1: Extract+Narrate fusionados en UNA llamada de IA. Hito 8.3:
        // se agregan nombre/training_location (bloqueantes) y datos físicos
        // (nunca bloqueantes, se preguntan una sola vez) — ver
        // App\Training\Support\OnboardingConversationService y D026/D033 en
        // docs/DECISIONS.md.
        if (! $profile->isOnboardingComplete($contact)) {
            $fragment = $this->buildContext($context, 'training_profile');
            $pendingFieldBeforeTurn = $profile->firstMissingOnboardingField($contact);

            $aiCallStartedAt = microtime(true);
            $result = $this->onboarding->extractAndRespond($body, $fragment->data, $tenant);
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

            $this->applyExtractedFields($contact, $profile, $extracted);

            // Los datos físicos se preguntan una sola vez (Hito 8.3, aprobado
            // explícitamente): si el campo pendiente ANTES de este turno ya
            // era 'physical_stats', este turno fue la oportunidad de
            // responder — se acepta lo que haya llegado (parcial o nada) y
            // no se vuelve a insistir, sin importar si la IA extrajo algo.
            if ($pendingFieldBeforeTurn === 'physical_stats') {
                $profile->update(['physical_stats_asked' => true]);
            }

            $profile = $profile->fresh();
            $contact = $contact->fresh();

            if (! $profile->isOnboardingComplete($contact)) {
                $realMissingField = $profile->firstMissingOnboardingField($contact);
                $question = $this->onboarding->resolveQuestion($realMissingField, $result['next_action'], $result['response']);

                // Métricas Hito 5.1: comparar contra la línea base de 2
                // llamadas/30-45s — ver docs/DECISIONS.md (D026).
                Log::info('ONBOARDING_TURN_METRICS', [
                    'tenant_id' => $tenant->id,
                    'contact_id' => $contact->id,
                    'ai_call_elapsed_ms' => $aiCallElapsedMs,
                    'ai_calls_count' => 1,
                    'used_ai_response' => $this->onboarding->usedAiResponse($realMissingField, $result['next_action'], $result['response']),
                ]);

                $this->reply($from, $question, $tenant);

                return;
            }
        }

        // 3. Acceso — frontera única hacia el sistema comercial (Hito 4).
        $freshContact = $contact->fresh();
        $gateResult = $this->accessGate->authorize($freshContact);

        if (! $gateResult->allowed) {
            $this->respondToDenial($gateResult->reason, $from, $tenant);

            return;
        }

        // 4. Reporte de ejecución (Hito 6): si hay una sesión pendiente con
        // ejercicios sin reportar, se intenta primero interpretar el
        // mensaje como un reporte antes de considerar generar/reentregar.
        $activeSessionFragment = $this->buildContext($context, 'active_workout_session');

        if ($activeSessionFragment->data !== null && $body !== '') {
            $handled = $this->tryHandleExecutionReport($activeSessionFragment->data, $body, $from, $tenant, $startedAt);

            if ($handled) {
                return;
            }
        }

        // 5. Generar y entregar. TrainingEngine vuelve a verificar el Gate
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
     * @param array{workout_session_id: int, unreported_exercises: array} $activeSessionData
     * @return bool true if this message was handled as a report attempt
     *              (successfully or not) and no further processing should
     *              happen this turn; false to let the caller fall through
     *              to the normal generate/deliver path.
     */
    private function tryHandleExecutionReport(array $activeSessionData, string $body, string $from, Tenant $tenant, float $startedAt): bool
    {
        $reportableExercises = $activeSessionData['unreported_exercises'];

        if ($reportableExercises === []) {
            return false;
        }

        Log::info('TRAINING_REPORT_ATTEMPT', ['workout_session_id' => $activeSessionData['workout_session_id']]);

        $extraction = $this->reportExtractor->extractReport($body, $reportableExercises, $tenant);

        if ($extraction['reports'] === [] && ! $extraction['session_finished']) {
            // Ninguna señal de reporte en el mensaje — se deja pasar para
            // que el flujo normal (reentregar la sesión pendiente) lo maneje.
            return false;
        }

        $session = WorkoutSession::find($activeSessionData['workout_session_id']);
        $outcome = $this->reportRecorder->record($session, $extraction);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info($outcome->hasAnyEffect() || $outcome->sessionCompleted ? 'TRAINING_REPORT_SUCCESS' : 'TRAINING_REPORT_INCOMPLETE', [
            'workout_session_id' => $session->id,
            'logged_count' => count($outcome->logged),
            'clarifications_count' => count($outcome->clarifications),
            'session_completed' => $outcome->sessionCompleted,
            'elapsed_ms' => $elapsedMs,
        ]);

        $this->reply($from, $this->buildReportResponseMessage($outcome), $tenant);

        return true;
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

    /**
     * Hito 8.3: `name` se persiste en Contact.customer_name (identidad, no
     * vive en TrainingProfile) — nunca se sobrescribe si el usuario ya tenía
     * un nombre guardado, salvo corrección explícita (fuera de alcance de
     * este hito: hoy simplemente no se vuelve a preguntar una vez existe).
     */
    private function applyExtractedFields(Contact $contact, TrainingProfile $profile, array $extracted): void
    {
        if ($extracted['name'] !== null && $contact->customer_name === null) {
            $contact->update(['customer_name' => $extracted['name']]);
        }

        $updates = [];

        foreach ([
            'goal', 'experience_level', 'primary_focus', 'secondary_focus',
            'restrictions', 'available_equipment',
            'equipment_fully_equipped', 'training_location', 'sessions_per_week',
            'age', 'sex', 'weight_kg', 'height_cm',
        ] as $field) {
            if ($extracted[$field] !== null) {
                $updates[$field] = $extracted[$field];
            }
        }

        // Hito 9.0: sessions_per_week por sí solo no cambiaba nada en
        // TrainingEngine — su único efecto real es derivar split_type,
        // determinísticamente, cada vez que se captura o se actualiza.
        if ($extracted['sessions_per_week'] !== null) {
            $updates['split_type'] = TrainingProfile::deriveSplitTypeFromSessionsPerWeek($extracted['sessions_per_week']);
        }

        if ($updates !== []) {
            $profile->update($updates);
        }
    }

    private function respondToDenial(?string $reason, string $from, Tenant $tenant): void
    {
        $message = $reason === 'safety_flagged'
            ? SafetySignalDetector::ESCALATION_MESSAGE
            : self::ACCESS_REQUIRED_MESSAGE;

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
