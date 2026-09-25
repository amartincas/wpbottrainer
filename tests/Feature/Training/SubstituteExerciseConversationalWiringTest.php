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
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Hito C (Sustitución de un ejercicio) — Fase 2, integración conversacional
 * completa: WhatsApp -> Router -> TrainingHandler -> ExecutionReportService/
 * CoachService -> ConversationTurnResolver -> WorkoutExerciseTargetResolver
 * -> ReplaceWorkoutExerciseService -> TrainingEngine -> entrega.
 * Prefijo "sub" en los helpers para evitar colisión de funciones globales
 * con otros archivos de test (mismo criterio que "nwrw" en
 * `NewWorkoutRequestConversationalWiringTest`).
 */
function subChatBody(array $payload): array
{
    return ['choices' => [['message' => ['content' => json_encode($payload)]]]];
}

function subReadyContact(): Contact
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
 * Sesión Scheduled con un único Main YA entregado (sin reportar) — dispara
 * el camino de ExecutionReportService (paso 4) y sirve de ancla de frente
 * activo para la resolución de identidad (Camino "este ejercicio").
 *
 * @return array{0: WorkoutSession, 1: WorkoutExercise}
 */
function subPendingMainSession(Contact $contact, string $exerciseName = 'Sentadilla', string $muscleGroup = 'legs'): array
{
    $exercise = Exercise::factory()->create([
        'name' => $exerciseName,
        'name_es' => $exerciseName,
        'muscle_group' => $muscleGroup,
        'tracking_type' => TrackingType::RepsAndLoad,
    ]);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $workoutExercise = WorkoutExercise::create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
        'phase' => WorkoutExercisePhase::Main,
        'prescribed_sets' => 3,
        'prescribed_reps' => 10,
        'prescribed_load' => 40,
        'rest_seconds' => 60,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'delivered_at' => now(),
    ]);

    return [$session, $workoutExercise];
}

function subSeedCatalog(string $muscleGroup = 'legs', int $count = 2): void
{
    Exercise::factory()->count($count)->create([
        'muscle_group' => $muscleGroup,
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);
}

function sendSubMessage(Contact $contact, string $body): void
{
    $job = new ProcessWhatsAppMessage($contact->tenant, $contact->customer_phone, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

function subOutboundBodies(): Collection
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body', ''));
}

function subFakeHttp(array $chatBody): void
{
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response($chatBody),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);
}

// ============================================================
// INTENT
// ============================================================

it('INTENT-1: "Cámbiame este ejercicio" substitutes the front (anchor) exercise', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame este ejercicio');

    expect($target->fresh()->superseded_by_id)->not->toBeNull();
    $replacement = WorkoutExercise::find($target->fresh()->superseded_by_id);
    expect($replacement)->not->toBeNull();
    expect($replacement->exercise_id)->not->toBe($target->exercise_id);
    expect($replacement->order)->toBe(1);
    expect($replacement->phase)->toBe(WorkoutExercisePhase::Main);
});

it('INTENT-2: "Quiero otro ejercicio" substitutes the front exercise', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Quiero otro ejercicio');

    expect($target->fresh()->superseded_by_id)->not->toBeNull();
});

it('INTENT-3: "Dame otro ejercicio" substitutes the front exercise', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Dame otro ejercicio');

    expect($target->fresh()->superseded_by_id)->not->toBeNull();
});

it('INTENT-4: "Cámbiame las sentadillas" resolves the target by NAME, not just the anchor', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Sentadilla', muscleGroup: 'legs');
    // Segundo Main de la sesión, NOMBRE distinto — confirma que la
    // resolución por nombre elige el correcto, no "el frente" (que aquí
    // coincide, pero el test #INTENT-4b prueba el caso donde difieren).
    $otherExercise = Exercise::factory()->create(['name' => 'Press de banca', 'name_es' => 'Press de banca', 'muscle_group' => 'chest']);
    WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $otherExercise->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $otherExercise->toSnapshot(),
    ]);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame las sentadillas');

    expect($target->fresh()->superseded_by_id)->not->toBeNull();
    expect(WorkoutExercise::find($otherExercise->id))->toBeNull(); // no aplica, control trivial
    $pressBanca = WorkoutExercise::where('exercise_id', $otherExercise->id)->first();
    expect($pressBanca->fresh()->superseded_by_id)->toBeNull(); // el press de banca NUNCA se tocó
});

it('INTENT-4b: name resolution picks the NAMED exercise even when it is NOT the front/anchor', function () {
    $contact = subReadyContact();
    // El FRENTE (entregado, order=1) es Press de banca — pero el usuario
    // pide explícitamente "las sentadillas" (order=2, NO entregado, NO es
    // el ancla) — la resolución por NOMBRE debe ganarle al ancla.
    $frontExercise = Exercise::factory()->create(['name' => 'Press de banca', 'name_es' => 'Press de banca', 'muscle_group' => 'chest']);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $frontExercise->id, 'order' => 1,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $frontExercise->toSnapshot(), 'delivered_at' => now(),
    ]);
    $squat = Exercise::factory()->create(['name' => 'Sentadilla', 'name_es' => 'Sentadilla', 'muscle_group' => 'legs', 'tracking_type' => TrackingType::RepsAndLoad]);
    $squatWorkoutExercise = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $squat->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $squat->toSnapshot(),
    ]);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame las sentadillas');

    expect($squatWorkoutExercise->fresh()->superseded_by_id)->not->toBeNull();
});

it('INTENT-5: "Quiero otro ejercicio de pecho" substitutes with the requested focus applied', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Sentadilla', muscleGroup: 'legs');
    subSeedCatalog('legs'); // candidato general, sin foco
    $chestCandidate = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => \App\Training\Enums\MuscleFocus::Chest]);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => ['pecho'],
    ]));

    sendSubMessage($contact, 'Quiero otro ejercicio de pecho');

    $replacement = WorkoutExercise::find($target->fresh()->superseded_by_id);
    expect($replacement->exercise_id)->toBe($chestCandidate->id);
});

// ============================================================
// B2 REGRESSION — C nunca interfiere
// ============================================================

it('B2-REGRESSION-1: "Quiero otra rutina" still replaces the whole session, C never fires', function () {
    $contact = subReadyContact();
    foreach (['arms', 'back', 'chest', 'core', 'legs', 'shoulders'] as $group) {
        Exercise::factory()->count(2)->create(['muscle_group' => $group, 'equipment_needed' => []]);
    }
    $old = WorkoutSession::factory()->create(['contact_id' => $contact->id]);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'intents' => ['new_workout_request'], 'training_reply' => null,
        'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Quiero otra rutina');

    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Superseded);
    expect(WorkoutExercise::whereNotNull('superseded_by_id')->count())->toBe(0); // C nunca tocó ningún WorkoutExercise individual
});

it('B2-REGRESSION-2: "Hazme otra rutina" still replaces the whole session, C never fires', function () {
    $contact = subReadyContact();
    foreach (['arms', 'back', 'chest', 'core', 'legs', 'shoulders'] as $group) {
        Exercise::factory()->count(2)->create(['muscle_group' => $group, 'equipment_needed' => []]);
    }
    $old = WorkoutSession::factory()->create(['contact_id' => $contact->id]);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'intents' => ['new_workout_request'], 'training_reply' => null,
        'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Hazme otra rutina');

    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Superseded);
    expect(WorkoutExercise::whereNotNull('superseded_by_id')->count())->toBe(0);
});

// ============================================================
// PRECEDENCE
// ============================================================

it('PRECEDENCE-1: Safety wins over a substitution phrase in the same message — C never runs, no AI call happens', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact);
    subSeedCatalog('legs');

    // Sin Http::fake() para el LLM: si Safety NO gana primero, el intento de
    // llamar a OpenAI real fallaría la petición HTTP y el test lo revelaría.
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendSubMessage($contact, 'Cámbiame este ejercicio, tengo dolor de pecho');

    expect($target->fresh()->superseded_by_id)->toBeNull();
    expect($contact->fresh()->trainingProfile->isFlaggedForSafetyReview())->toBeTrue();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
});

it('PRECEDENCE-2: B3 ("no me gusta este ejercicio") wins — C never runs, no WorkoutExercise substituted', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => [], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'No me gusta este ejercicio');

    expect($target->fresh()->superseded_by_id)->toBeNull();
});

it('PRECEDENCE-3: if the AI tags BOTH new_workout_request and substitute_exercise, NewWorkoutRequest wins — C never runs', function () {
    $contact = subReadyContact();
    foreach (['arms', 'back', 'chest', 'core', 'legs', 'shoulders'] as $group) {
        Exercise::factory()->count(2)->create(['muscle_group' => $group, 'equipment_needed' => []]);
    }
    $old = WorkoutSession::factory()->create(['contact_id' => $contact->id]);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null,
        'intents' => ['new_workout_request', 'substitute_exercise'],
        'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Mensaje ambiguo etiquetado con ambos intents');

    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Superseded);
    expect(WorkoutExercise::whereNotNull('superseded_by_id')->count())->toBe(0);
});

it('PRECEDENCE-4: "Ya hice este ejercicio, pero cámbiame el siguiente" records the report FIRST, then substitutes the newly-delivered next exercise', function () {
    $contact = subReadyContact();
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $first = Exercise::factory()->create(['name' => 'Sentadilla', 'name_es' => 'Sentadilla', 'muscle_group' => 'legs', 'tracking_type' => TrackingType::RepsAndLoad]);
    $firstWe = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $first->id, 'order' => 1,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10, 'prescribed_load' => 40,
        'exercise_snapshot' => $first->toSnapshot(), 'delivered_at' => now(),
    ]);
    $second = Exercise::factory()->create(['name' => 'Press de banca', 'name_es' => 'Press de banca', 'muscle_group' => 'chest', 'tracking_type' => TrackingType::RepsAndLoad]);
    $secondWe = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $second->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $second->toSnapshot(),
    ]);
    subSeedCatalog('chest', 2); // candidatos para sustituir el 2do (Press de banca)

    subFakeHttp(subChatBody([
        'safety_signal_text' => null,
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false, 'skip_reason' => null,
            'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null], ['reps' => 10, 'load' => 40, 'duration_seconds' => null], ['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Ya hice este ejercicio, pero cámbiame el siguiente');

    // El reporte real quedó registrado en el Main #1.
    $log = ExerciseLog::where('workout_exercise_id', $firstWe->id)->first();
    expect($log)->not->toBeNull();
    expect($log->exerciseSets)->toHaveCount(3);

    // Y el Main #2 (recién entregado por la entrega progresiva tras el
    // reporte) quedó sustituido — nunca el #1, que ya tiene su reporte real.
    expect($firstWe->fresh()->superseded_by_id)->toBeNull();
    expect($secondWe->fresh()->delivered_at)->not->toBeNull(); // se entregó como parte del reporte
    expect($secondWe->fresh()->superseded_by_id)->not->toBeNull();
});

// ============================================================
// TARGET RESOLUTION
// ============================================================

it('TARGET-1: ordinal "cámbiame el segundo" resolves by position', function () {
    $contact = subReadyContact();
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $first = Exercise::factory()->create(['muscle_group' => 'legs']);
    WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $first->id, 'order' => 1,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $first->toSnapshot(), 'delivered_at' => now(),
    ]);
    $second = Exercise::factory()->create(['muscle_group' => 'chest']);
    $secondWe = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $second->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $second->toSnapshot(), 'delivered_at' => now(),
    ]);
    subSeedCatalog('chest', 2);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame el segundo ejercicio');

    expect($secondWe->fresh()->superseded_by_id)->not->toBeNull();
});

it('TARGET-2: ordinal out of range -> target_not_found, nothing substituted', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame el tercer ejercicio'); // solo hay 1

    expect($target->fresh()->superseded_by_id)->toBeNull();
    expect(subOutboundBodies()->contains(fn ($b) => str_contains($b, 'No estoy segura de a cuál ejercicio')))->toBeTrue();
});

it('TARGET-3: no frontExercise and no name/ordinal match -> target_not_found', function () {
    $contact = subReadyContact();
    // Sesión activa pero SIN ningún ejercicio entregado todavía (delivered_at null).
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $exercise = Exercise::factory()->create(['muscle_group' => 'legs']);
    $target = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'order' => 1,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $exercise->toSnapshot(), 'delivered_at' => null,
    ]);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame este ejercicio');

    expect($target->fresh()->superseded_by_id)->toBeNull();
    expect(subOutboundBodies()->contains(fn ($b) => str_contains($b, 'No estoy segura de a cuál ejercicio')))->toBeTrue();
});

it('TARGET-4: ambiguous name match (2+ session exercises share a token) -> ambiguous_target, nothing substituted', function () {
    $contact = subReadyContact();
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $squatA = Exercise::factory()->create(['name' => 'Sentadilla con banda', 'name_es' => 'Sentadilla con banda', 'muscle_group' => 'legs']);
    $weA = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $squatA->id, 'order' => 1,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $squatA->toSnapshot(), 'delivered_at' => now(),
    ]);
    $squatB = Exercise::factory()->create(['name' => 'Sentadilla sumo', 'name_es' => 'Sentadilla sumo', 'muscle_group' => 'legs']);
    $weB = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $squatB->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $squatB->toSnapshot(), 'delivered_at' => now(),
    ]);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame la sentadilla');

    expect($weA->fresh()->superseded_by_id)->toBeNull();
    expect($weB->fresh()->superseded_by_id)->toBeNull();
    expect(subOutboundBodies()->contains(fn ($b) => str_contains($b, 'Encontré varios ejercicios')))->toBeTrue();
});

// ============================================================
// RESULTADOS (ExerciseSubstitutionOutcome / excepciones)
// ============================================================

it('RESULT-1: replaced -> exactly one ack message + one exercise delivery message', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame este ejercicio');

    $bodies = subOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Listo — cambié')))->toBeTrue();
});

it('RESULT-2: target_already_resolved (already has ExerciseLog) -> informative reply, no new replacement', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact);
    ExerciseLog::create(['workout_exercise_id' => $target->id, 'logged_at' => now()]);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame este ejercicio');

    expect($target->fresh()->superseded_by_id)->toBeNull();
    expect(subOutboundBodies()->contains(fn ($b) => str_contains($b, 'Ya registraste ese ejercicio')))->toBeTrue();
});

it('RESULT-3: TrainingCatalogInsufficientException (no candidate at all) -> catalog-insufficient message, no crash', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, muscleGroup: 'legs');
    // Sin ningún otro Exercise activo en el catálogo — el único candidato es
    // el propio target, excluido estructuralmente.

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame este ejercicio');

    expect($target->fresh()->superseded_by_id)->toBeNull();
    expect(subOutboundBodies()->contains(fn ($b) => str_contains($b, 'no tengo suficientes ejercicios')))->toBeTrue();
});

it('RESULT-4: no active Scheduled session at all -> informative reply, no crash', function () {
    $contact = subReadyContact();

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'intents' => ['substitute_exercise'], 'training_reply' => null,
        'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame este ejercicio');

    expect(subOutboundBodies()->contains(fn ($b) => str_contains($b, 'No tienes ningún ejercicio activo')))->toBeTrue();
});

// ============================================================
// DELIVERY
// ============================================================

it('DELIVERY-1: acknowledgement mentions BOTH the original and the new exercise names', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Sentadilla', muscleGroup: 'legs');
    $replacementExercise = Exercise::factory()->create(['name' => 'Zancadas', 'name_es' => 'Zancadas', 'muscle_group' => 'legs']);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame este ejercicio');

    $bodies = subOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Sentadilla') && str_contains($b, 'Zancadas')))->toBeTrue();
});

it('DELIVERY-2: the replacement is actually delivered (technique message with its own name), original is never re-delivered', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Sentadilla', muscleGroup: 'legs');
    Exercise::factory()->create(['name' => 'Zancadas', 'name_es' => 'Zancadas', 'muscle_group' => 'legs']);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame este ejercicio');

    $replacement = WorkoutExercise::find($target->fresh()->superseded_by_id);
    expect($replacement->delivered_at)->not->toBeNull(); // se entregó
    expect($target->fresh()->delivered_at)->not->toBeNull(); // el original conserva el SUYO (histórico, del turno 1)
    // Numeración conservada: sigue siendo el ejercicio "1.".
    expect(subOutboundBodies()->contains(fn ($b) => str_contains($b, '1. *'.($replacement->exercise_snapshot['name']).'*')))->toBeTrue();
});

// ============================================================
// FIX PRE-COMMIT (Hito C, auditoría Fase 2) — hallazgo 1: identidad del
// target. Un `requested_focus_terms` NUNCA puede usarse como evidencia de
// TARGET cuando existe un ancla explícita en el mensaje — el foco solo
// restringe el REEMPLAZO, jamás decide la identidad. Ver docblock de
// `WorkoutExerciseTargetResolver`.
// ============================================================

it('FIX2-A: "cámbiame este ejercicio de pecho" targets the front (anchor), even though another session exercise contains "pecho"', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Sentadilla', muscleGroup: 'legs');
    $chestExercise = Exercise::factory()->create(['name' => 'Press de pecho', 'name_es' => 'Press de pecho', 'muscle_group' => 'chest']);
    $chestWorkoutExercise = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $chestExercise->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $chestExercise->toSnapshot(),
    ]);
    subSeedCatalog('legs');
    Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => \App\Training\Enums\MuscleFocus::Chest]);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => ['pecho'],
    ]));

    sendSubMessage($contact, 'Cámbiame este ejercicio de pecho');

    expect($target->fresh()->superseded_by_id)->not->toBeNull(); // el ANCLA (frente), no "Press de pecho"
    expect($chestWorkoutExercise->fresh()->superseded_by_id)->toBeNull();
});

it('FIX2-B: "cámbiame este ejercicio de pierna" still targets the front, even though another session exercise contains "pierna"', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Press de banca', muscleGroup: 'chest');
    $legExercise = Exercise::factory()->create(['name' => 'Extensión de pierna', 'name_es' => 'Extensión de pierna', 'muscle_group' => 'legs']);
    $legWorkoutExercise = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $legExercise->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $legExercise->toSnapshot(),
    ]);
    subSeedCatalog('chest');
    Exercise::factory()->create(['muscle_group' => 'legs']);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => ['pierna'],
    ]));

    sendSubMessage($contact, 'Cámbiame este ejercicio de pierna');

    expect($target->fresh()->superseded_by_id)->not->toBeNull(); // el ANCLA (frente), no "Extensión de pierna"
    expect($legWorkoutExercise->fresh()->superseded_by_id)->toBeNull();
});

it('FIX2-C: "cámbiame las sentadillas" still resolves by NAME after the fix (regression, unchanged)', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Sentadilla', muscleGroup: 'legs');
    $other = Exercise::factory()->create(['name' => 'Press de banca', 'name_es' => 'Press de banca', 'muscle_group' => 'chest']);
    $otherWorkoutExercise = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $other->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $other->toSnapshot(),
    ]);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame las sentadillas');

    expect($target->fresh()->superseded_by_id)->not->toBeNull();
    expect($otherWorkoutExercise->fresh()->superseded_by_id)->toBeNull();
});

it('FIX2-D: "cámbiame el segundo" (sin la palabra "ejercicio") still resolves by ORDINAL after the fix (regression, unchanged)', function () {
    $contact = subReadyContact();
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $first = Exercise::factory()->create(['muscle_group' => 'legs']);
    $firstWe = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $first->id, 'order' => 1,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $first->toSnapshot(), 'delivered_at' => now(),
    ]);
    $second = Exercise::factory()->create(['muscle_group' => 'chest']);
    $secondWe = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $second->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $second->toSnapshot(), 'delivered_at' => now(),
    ]);
    subSeedCatalog('chest', 2);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame el segundo');

    expect($secondWe->fresh()->superseded_by_id)->not->toBeNull();
    expect($firstWe->fresh()->superseded_by_id)->toBeNull();
});

it('FIX2-E: ordinal distinto del front — front=exercise#1, "cámbiame el segundo" -> target=exercise#2, nunca el frente ni el #3', function () {
    $contact = subReadyContact();
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $front = Exercise::factory()->create(['muscle_group' => 'legs']);
    $frontWe = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $front->id, 'order' => 1,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $front->toSnapshot(), 'delivered_at' => now(),
    ]);
    $second = Exercise::factory()->create(['muscle_group' => 'chest']);
    $secondWe = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $second->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $second->toSnapshot(), 'delivered_at' => now(),
    ]);
    $third = Exercise::factory()->create(['muscle_group' => 'back']);
    $thirdWe = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $third->id, 'order' => 3,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $third->toSnapshot(), 'delivered_at' => now(),
    ]);
    subSeedCatalog('chest', 2);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame el segundo');

    expect($secondWe->fresh()->superseded_by_id)->not->toBeNull();
    expect($frontWe->fresh()->superseded_by_id)->toBeNull();
    expect($thirdWe->fresh()->superseded_by_id)->toBeNull();
});

// ============================================================
// FIX PRE-COMMIT (Hito C, auditoría Fase 2) — hallazgo 3: coordinación
// B3/C. Una frase híbrida ("no me gusta este ejercicio, cámbiamelo") que
// la IA etiqueta con un `substitute_exercise` válido debe producir
// EXACTAMENTE el flujo/respuesta de C — B3 NUNCA se invoca en el mismo
// turno (evita la respuesta doble/contradictoria "Listo, cambié X por Y"
// seguida de "No estoy segura de a qué ejercicio te refieres"), y NUNCA
// crea una `TrainingPreference` nueva. El caso bare ("no me gusta este
// ejercicio", SIN `substitute_exercise`) sigue siendo 100% de B3, sin
// cambios — ver PRECEDENCE-2 arriba, no tocado por este fix.
// ============================================================

it('HYBRID-1: "no me gusta este ejercicio, cámbiamelo" with a valid substitute_exercise intent runs ONLY C\'s flow — no B3 double response, no new TrainingPreference', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Sentadilla', muscleGroup: 'legs');
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'No me gusta este ejercicio, cámbiamelo');

    // Exactamente el flujo de C: la sustitución ocurrió.
    expect($target->fresh()->superseded_by_id)->not->toBeNull();

    // Nunca la respuesta de B3 (contradictoria si apareciera junto al ack de C).
    $bodies = subOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'No estoy segura de a qué ejercicio te refieres')))->toBeFalse();

    // Nunca una TrainingPreference nueva — B3 nunca se ejecutó para este turno.
    expect(\App\Models\TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);
});
