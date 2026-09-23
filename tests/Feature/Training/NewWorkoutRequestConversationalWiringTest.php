<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrackingType;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Facades\Http;

/**
 * Hito B2 (Nueva rutina durante sesión activa) — flujo completo extremo a
 * extremo (WhatsApp -> Router -> TrainingHandler -> ExecutionReportService/
 * CoachService -> ConversationTurnResolver -> ReplaceWorkoutSessionService).
 * Prefijo "nwrw" (new-workout-request-wiring) en los helpers para evitar
 * colisión de funciones globales con otros archivos de test.
 */
function nwrwChatBody(array $payload): array
{
    return ['choices' => [['message' => ['content' => json_encode($payload)]]]];
}

function nwrwReadyContact(): Contact
{
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'health_screening_asked' => true,
        'split_type' => SplitType::FullBody,
    ]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

/**
 * Sesión activa con un Main YA entregado (sin reportar) — dispara el
 * camino de `ExecutionReportService` (paso 4 de TrainingHandler::handle()).
 *
 * @return array{0: WorkoutSession, 1: WorkoutExercise}
 */
function nwrwPendingMainSession(Contact $contact): array
{
    $exercise = Exercise::factory()->create(['name' => 'Sentadilla', 'tracking_type' => TrackingType::RepsAndLoad]);
    $session = WorkoutSession::factory()->create([
        'contact_id' => $contact->id,
        'status' => WorkoutSessionStatus::Scheduled,
        'prescription_context_snapshot' => [
            'schema_version' => 1, 'goal' => 'general_fitness', 'experience_level' => 'beginner',
            'primary_focus' => [], 'secondary_focus' => [], 'decided_focus' => 'arms,back,chest,core,legs,shoulders',
            'requested_focus' => [], 'requested_focus_coverage' => [], 'split_type' => 'full_body',
            'training_location' => 'home', 'available_equipment' => [], 'equipment_fully_equipped' => true,
            'active_safety_tags' => [], 'generated_at' => now()->toISOString(),
        ],
    ]);
    $workoutExercise = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 3,
        'prescribed_reps' => 10,
        'prescribed_load' => 40,
        'delivered_at' => now(),
    ]);

    return [$session, $workoutExercise];
}

/**
 * Catálogo elegible mínimo para que `TrainingEngine::decideNextSession()`
 * nunca falle por catálogo insuficiente, sin importar el foco.
 */
function nwrwSeedCatalog(): void
{
    foreach (['arms', 'back', 'chest', 'core', 'legs', 'shoulders'] as $group) {
        Exercise::factory()->count(2)->create([
            'muscle_group' => $group,
            'difficulty_level' => 'beginner',
            'equipment_needed' => [],
        ]);
    }
}

function sendNwrwMessage(Contact $contact, string $body): void
{
    $tenant = $contact->tenant;
    $job = new ProcessWhatsAppMessage($tenant, $contact->customer_phone, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

// ── E2E-1: "Quiero otra rutina" sin Main pendiente (camino CoachService) ─

it('E2E-1: "quiero otra rutina" without a pending report replaces the session via CoachService path', function () {
    nwrwSeedCatalog();
    $contact = nwrwReadyContact();
    $old = WorkoutSession::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(nwrwChatBody([
            'safety_signal_text' => null, 'intents' => ['new_workout_request'], 'training_reply' => null,
            'requested_focus_terms' => [],
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendNwrwMessage($contact, 'Quiero otra rutina');

    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Superseded);
    $new = WorkoutSession::where('contact_id', $contact->id)->where('status', WorkoutSessionStatus::Scheduled)->first();
    expect($new)->not->toBeNull();
    expect($old->fresh()->superseded_by_id)->toBe($new->id);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Tu entrenamiento de hoy'));
});

// ── E2E-2: "No quiero esta rutina, dame otra" CON Main pendiente ────────

it('E2E-2: "no quiero esta rutina, dame otra" with a Main pending is NEVER recorded as skip_reason=dont_want, and replaces the session', function () {
    nwrwSeedCatalog();
    $contact = nwrwReadyContact();
    [$old, $mainExercise] = nwrwPendingMainSession($contact);

    // Simula la clasificación CORRECTA esperada del prompt actualizado de
    // ExecutionReportService: "reports" vacío (la frase no reporta nada del
    // ejercicio actual) + intents=["new_workout_request"].
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(nwrwChatBody([
            'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
            'intents' => ['new_workout_request'], 'training_reply' => null,
            'requested_focus_terms' => [],
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendNwrwMessage($contact, 'No quiero esta rutina, dame otra');

    // CRÍTICO — nunca se creó un ExerciseLog con skip_reason=dont_want (ni
    // ningún ExerciseLog) para el Main que estaba pendiente.
    expect(ExerciseLog::where('workout_exercise_id', $mainExercise->id)->count())->toBe(0);

    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Superseded);
    $new = WorkoutSession::where('contact_id', $contact->id)->where('status', WorkoutSessionStatus::Scheduled)->first();
    expect($new)->not->toBeNull();
});

// ── E2E-3: mensaje mixto — reporte real + reemplazo ─────────────────────

it('E2E-3: "hice las tres series, pero ya no quiero seguir con esta rutina, dame otra" records the report on the OLD session THEN replaces it', function () {
    nwrwSeedCatalog();
    $contact = nwrwReadyContact();
    [$old, $mainExercise] = nwrwPendingMainSession($contact);
    // Segundo Main sin entregar/reportar — evita que el único reporte de
    // arriba complete automáticamente la sesión (maybeCompleteSession()),
    // para poder observar realmente la transición Scheduled -> Superseded
    // en vez de Scheduled -> Completed (un desenlace también válido, pero
    // que no es lo que este caso puntual quiere aislar).
    WorkoutExercise::factory()->create([
        'workout_session_id' => $old->id,
        'exercise_snapshot' => ['name' => 'Zancadas', 'primary_muscle' => 'quads'],
        'order' => 2,
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(nwrwChatBody([
            'safety_signal_text' => null,
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => false, 'skip_reason' => null,
                'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null], ['reps' => 10, 'load' => 40, 'duration_seconds' => null], ['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
            'intents' => ['new_workout_request'], 'training_reply' => null,
            'requested_focus_terms' => [],
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendNwrwMessage($contact, 'Hice las tres series, pero ya no quiero seguir con esta rutina, dame otra');

    // El reporte real quedó registrado en el Main de la sesión VIEJA.
    $log = ExerciseLog::where('workout_exercise_id', $mainExercise->id)->first();
    expect($log)->not->toBeNull();
    expect($log->exerciseSets)->toHaveCount(3);

    // Y la sesión vieja (ya con su reporte real intacto) quedó reemplazada.
    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Superseded);
    $new = WorkoutSession::where('contact_id', $contact->id)->where('status', WorkoutSessionStatus::Scheduled)->first();
    expect($new)->not->toBeNull();
    expect($new->id)->not->toBe($old->id);
});

// ── E2E-4: "Dame otra rutina de pecho" — requested focus explícito ──────

it('E2E-4: "dame otra rutina de pecho" creates the replacement session with requested_focus=chest', function () {
    nwrwSeedCatalog();
    $contact = nwrwReadyContact();
    $old = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    $primaryFocusBefore = $contact->fresh()->trainingProfile->primary_focus;
    $secondaryFocusBefore = $contact->fresh()->trainingProfile->secondary_focus;

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(nwrwChatBody([
            'safety_signal_text' => null, 'intents' => ['new_workout_request'], 'training_reply' => null,
            'requested_focus_terms' => ['pecho'],
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendNwrwMessage($contact, 'Dame otra rutina de pecho');

    $new = WorkoutSession::where('contact_id', $contact->id)->where('status', WorkoutSessionStatus::Scheduled)->first();
    expect($new->prescription_context_snapshot['requested_focus'])->toBe([
        ['key' => 'chest', 'muscles' => ['chest']],
    ]);
    // Nunca convertido en preferencia permanente.
    expect($contact->fresh()->trainingProfile->primary_focus)->toBe($primaryFocusBefore);
    expect($contact->fresh()->trainingProfile->secondary_focus)->toBe($secondaryFocusBefore);
});

// ── E2E-5: inconsistencia — más de una Scheduled ────────────────────────

it('E2E-5: more than one Scheduled session degrades gracefully (no crash, no arbitrary pick, no session created/changed)', function () {
    nwrwSeedCatalog();
    $contact = nwrwReadyContact();
    $first = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    $second = WorkoutSession::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(nwrwChatBody([
            'safety_signal_text' => null, 'intents' => ['new_workout_request'], 'training_reply' => null,
            'requested_focus_terms' => [],
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendNwrwMessage($contact, 'Quiero otra rutina');

    // Ninguna de las dos sesiones preexistentes fue tocada; no se creó una
    // tercera.
    expect($first->fresh()->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect($second->fresh()->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(2);
});

// ── E2E-6 (control negativo): frase de Hito C nunca activa el reemplazo ─

it('E2E-6 (control): when the AI correctly does NOT classify an exercise-level phrase as new_workout_request, no replacement happens', function () {
    nwrwSeedCatalog();
    $contact = nwrwReadyContact();
    [$old, $mainExercise] = nwrwPendingMainSession($contact);
    // Segundo Main sin entregar — mismo motivo que en E2E-3: evita que este
    // único reporte complete la sesión por su cuenta, para que la
    // aserción "sigue Scheduled" aísle realmente la pregunta de si
    // new_workout_request se activó o no (nunca un efecto colateral de
    // maybeCompleteSession()).
    WorkoutExercise::factory()->create([
        'workout_session_id' => $old->id,
        'exercise_snapshot' => ['name' => 'Zancadas', 'primary_muscle' => 'quads'],
        'order' => 2,
    ]);

    // "no quiero este ejercicio, dame otro" — clasificación correcta
    // esperada (Hito C, no B2): reporte de not_performed/dont_want del
    // ejercicio actual, SIN new_workout_request.
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(nwrwChatBody([
            'safety_signal_text' => null,
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => true, 'skip_reason' => 'dont_want',
                'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false, 'intents' => [], 'training_reply' => null,
            'requested_focus_terms' => [],
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendNwrwMessage($contact, 'No quiero este ejercicio, dame otro');

    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect($old->fresh()->superseded_by_id)->toBeNull();
    $log = ExerciseLog::where('workout_exercise_id', $mainExercise->id)->first();
    expect($log)->not->toBeNull();
    expect($log->skip_reason->value)->toBe('dont_want');
});

// ── E2E-7 (revisión final B2.3, punto 1): doble acción contradictoria ───

it('E2E-7: if the AI contradictorily tags BOTH continue_training and new_workout_request, only the replacement happens — never a "pending session" message for the old one', function () {
    nwrwSeedCatalog();
    $contact = nwrwReadyContact();
    $old = WorkoutSession::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(nwrwChatBody([
            'safety_signal_text' => null,
            'intents' => ['continue_training', 'new_workout_request'],
            'training_reply' => null, 'requested_focus_terms' => [],
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendNwrwMessage($contact, 'Mensaje ambiguo que la IA etiquetó con ambos intents');

    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Superseded);
    $new = WorkoutSession::where('contact_id', $contact->id)->where('status', WorkoutSessionStatus::Scheduled)->first();
    expect($new)->not->toBeNull();
    expect($old->fresh()->superseded_by_id)->toBe($new->id);

    // Nunca se envió el mensaje de "sesión pendiente" que DeliverSession
    // hubiera generado si se hubiera ejecutado también.
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'sesión de entrenamiento pendiente'));
});
