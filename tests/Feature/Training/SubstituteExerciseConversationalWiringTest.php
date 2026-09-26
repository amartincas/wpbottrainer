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

// ============================================================
// C-REUSE (fix post-deploy, auditoría de reutilización intra-sesión, E2E
// real) — patrón real observado: sustituciones ENCADENADAS dentro de la
// MISMA sesión no deben poder reintroducir un ejercicio ya superseded en
// una sustitución anterior de esa sesión.
// ============================================================

it('C-REUSE-E2E: a second substitution never reuses an exercise superseded earlier in the same session', function () {
    $contact = subReadyContact();
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);

    $exerciseA = Exercise::factory()->create(['name' => 'Ejercicio A', 'name_es' => 'Ejercicio A', 'muscle_group' => 'legs']);
    $weA = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $exerciseA->id, 'order' => 1,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $exerciseA->toSnapshot(), 'delivered_at' => now(),
    ]);
    $exerciseB = Exercise::factory()->create(['name' => 'Ejercicio B', 'name_es' => 'Ejercicio B', 'muscle_group' => 'legs']);
    WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $exerciseB->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $exerciseB->toSnapshot(), 'delivered_at' => now(),
    ]);
    $exerciseC = Exercise::factory()->create(['name' => 'Ejercicio C', 'name_es' => 'Ejercicio C', 'muscle_group' => 'legs']);
    $weC = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $exerciseC->id, 'order' => 3,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $exerciseC->toSnapshot(), 'delivered_at' => now(),
    ]);

    // Único candidato real disponible para la PRIMERA sustitución.
    $exerciseD = Exercise::factory()->create(['name' => 'Ejercicio D', 'name_es' => 'Ejercicio D', 'muscle_group' => 'legs']);

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    // Primera sustitución: A -> D.
    sendSubMessage($contact, 'Cámbiame el primero');

    expect($weA->fresh()->superseded_by_id)->not->toBeNull();
    $weD = WorkoutExercise::find($weA->fresh()->superseded_by_id);
    expect($weD->exercise_id)->toBe($exerciseD->id);

    // Único candidato REALMENTE NUEVO disponible para la SEGUNDA
    // sustitución. exerciseA (id menor, creado antes -> ganaría el
    // desempate por id si el bug de reutilización intra-sesión reapareciera)
    // NUNCA debe volver a aparecer como candidato, pese a que su fila
    // original ya no está activa (superseded).
    $exerciseE = Exercise::factory()->create(['name' => 'Ejercicio E', 'name_es' => 'Ejercicio E', 'muscle_group' => 'legs']);

    // Segunda sustitución: C -> ? (nunca A, el histórico superseded de esta
    // misma sesión).
    sendSubMessage($contact, 'Cámbiame el tercero');

    expect($weC->fresh()->superseded_by_id)->not->toBeNull();
    $replacementOfC = WorkoutExercise::find($weC->fresh()->superseded_by_id);
    expect($replacementOfC->exercise_id)->toBe($exerciseE->id);
    expect($replacementOfC->exercise_id)->not->toBe($exerciseA->id);
});

// ============================================================
// C-ACTION-CONFLICT (fix conflicto de acciones, hallazgo real de auditoría
// E2E post-D6 — sesión #48 de staging): un mismo turno con
// RecordExecutionReport + SubstituteExercise NUNCA debe crear un
// ExerciseLog "de paso" para el ejercicio objetivo ni dejar que la
// sustitución recaiga sobre el ejercicio SIGUIENTE en vez del original.
// ============================================================

it('C-ACTION-1: "Dame otro ejercicio" con un reporte "dont_want" accidental del LLM sobre el ejercicio actual — solo se sustituye, sin ExerciseLog', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Ejercicio A', muscleGroup: 'legs');
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null,
        'reports' => [[
            'exercise_name' => null, 'not_performed' => true, 'skip_reason' => 'dont_want',
            'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Dame otro ejercicio');

    // Sustitución real, sobre el ejercicio ORIGINAL (el frente cuando
    // comenzó el turno) — nunca sobre "el siguiente".
    expect($target->fresh()->superseded_by_id)->not->toBeNull();
    $replacement = WorkoutExercise::find($target->fresh()->superseded_by_id);
    expect($replacement)->not->toBeNull();

    // El reporte "dont_want" NUNCA se persiste — es el mismo hallazgo que la
    // sustitución, no un hecho adicional.
    expect(ExerciseLog::where('workout_exercise_id', $target->id)->exists())->toBeFalse();
});

it('C-ACTION-2: exactamente UNA sustitución — el replacement nunca es sustituido de nuevo en el mismo turno', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Ejercicio A', muscleGroup: 'legs');
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null,
        'reports' => [[
            'exercise_name' => null, 'not_performed' => true, 'skip_reason' => 'dont_want',
            'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Dame otro ejercicio');

    expect(WorkoutExercise::whereNotNull('superseded_by_id')->count())->toBe(1);
    $replacement = WorkoutExercise::find($target->fresh()->superseded_by_id);
    expect($replacement->superseded_by_id)->toBeNull();
});

it('C-ACTION-3: "Quiero otro ejercicio" sin ningún reporte en absoluto — comportamiento idéntico (regresión de INTENT-2)', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Quiero otro ejercicio');

    expect($target->fresh()->superseded_by_id)->not->toBeNull();
    expect(ExerciseLog::where('workout_exercise_id', $target->id)->exists())->toBeFalse();
});

it('C-ACTION-4: "Hice 3 series de 10 con 8kg" — reporte real normal, sin sustitución', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Ejercicio A', muscleGroup: 'legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null,
        'reports' => [[
            'exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
            'sets' => [['reps' => 10, 'load' => 8, 'duration_seconds' => null], ['reps' => 10, 'load' => 8, 'duration_seconds' => null], ['reps' => 10, 'load' => 8, 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
        'intents' => [], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Hice 3 series de 10 con 8kg');

    $log = ExerciseLog::where('workout_exercise_id', $target->id)->first();
    expect($log)->not->toBeNull();
    expect($log->exerciseSets)->toHaveCount(3);
    expect($target->fresh()->superseded_by_id)->toBeNull();
});

it('C-ACTION-5: "Cámbiame este ejercicio" — sustitución sin ExerciseLog automático (regresión de INTENT-1)', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Cámbiame este ejercicio');

    expect($target->fresh()->superseded_by_id)->not->toBeNull();
    expect(ExerciseLog::where('workout_exercise_id', $target->id)->exists())->toBeFalse();
});

it('C-ACTION-6: "No me gusta este ejercicio" — B3 Preference, nunca SubstituteExercise (regresión de PRECEDENCE-2)', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Sentadilla', muscleGroup: 'legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => [], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'No me gusta este ejercicio');

    expect($target->fresh()->superseded_by_id)->toBeNull();
    expect(WorkoutExercise::whereNotNull('superseded_by_id')->count())->toBe(0);
});

it('C-ACTION-7: "No puedo hacer este ejercicio" (cant_do, sin substitute_exercise) — reporte real, nunca sustitución automática', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Ejercicio A', muscleGroup: 'legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null,
        'reports' => [[
            'exercise_name' => null, 'not_performed' => true, 'skip_reason' => 'cant_do',
            'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
        'intents' => [], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'No puedo hacer este ejercicio');

    // Sin SubstituteExercise en el turno, el filtro de conflicto NUNCA se
    // activa — el reporte "cant_do" se persiste normalmente, como siempre.
    $log = ExerciseLog::where('workout_exercise_id', $target->id)->first();
    expect($log)->not->toBeNull();
    expect($log->skip_reason->value)->toBe('cant_do');
    expect($target->fresh()->superseded_by_id)->toBeNull();
});

it('C-ACTION-8: mensaje de seguridad ("...me duele la rodilla") — Safety intacta, ni reporte ni sustitución (regresión de PRECEDENCE-1)', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact);

    subFakeHttp(subChatBody([
        'safety_signal_text' => 'dolor de rodilla', 'reports' => [], 'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'No puedo hacer este ejercicio porque me duele la rodilla');

    expect($target->fresh()->superseded_by_id)->toBeNull();
    expect(ExerciseLog::where('workout_exercise_id', $target->id)->exists())->toBeFalse();
});

it('C-ACTION-9: conflicto puro + session_finished=true — NUNCA se dispara el mensaje de "pendientes", solo la sustitución', function () {
    $contact = subReadyContact();
    [$session, $target] = subPendingMainSession($contact, exerciseName: 'Ejercicio A', muscleGroup: 'legs');
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null,
        'reports' => [[
            'exercise_name' => null, 'not_performed' => true, 'skip_reason' => 'dont_want',
            'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        // Hallazgo real de la micro-auditoría pre-commit: session_finished=true
        // viaja adjunto al MISMO reporte conflictivo (nunca una segunda
        // señal independiente en este escenario).
        'session_finished' => true,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Dame otro ejercicio');

    // Sustitución real, sin ExerciseLog, sin cierre de sesión.
    expect($target->fresh()->superseded_by_id)->not->toBeNull();
    expect(ExerciseLog::where('workout_exercise_id', $target->id)->exists())->toBeFalse();
    expect($session->fresh()->status)->not->toBe(WorkoutSessionStatus::Completed);

    // Exactamente 2 mensajes de texto reales en todo el turno (acuse de
    // sustitución + técnica del reemplazo) — NUNCA un tercero de
    // sessionCloseComposer ("aún tienes pendientes"/cierre).
    $nonEmptyBodies = subOutboundBodies()->filter(fn ($b) => $b !== '');
    expect($nonEmptyBodies)->toHaveCount(2);
    expect($nonEmptyBodies->contains(fn ($b) => str_contains($b, 'Listo — cambié')))->toBeTrue();
});

it('C-ACTION-MULTI-REPORT: conflicto + reporte real de OTRO ejercicio en el mismo turno — se elimina solo el conflictivo, el real se conserva', function () {
    $contact = subReadyContact();
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);

    // Frente real: "Ejercicio A" — el reporte anónimo (exercise_name=null)
    // se le atribuye a él vía $frontExerciseId.
    $exerciseA = Exercise::factory()->create(['name' => 'Ejercicio A', 'name_es' => 'Ejercicio A', 'muscle_group' => 'legs', 'tracking_type' => TrackingType::RepsAndLoad]);
    $weA = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $exerciseA->id, 'order' => 1,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $exerciseA->toSnapshot(), 'delivered_at' => now(),
    ]);
    // Un SEGUNDO ejercicio, nombrado explícitamente en el reporte real —
    // nunca el objetivo de la sustitución, ajeno al conflicto.
    $exercisePress = Exercise::factory()->create(['name' => 'Press de banca', 'name_es' => 'Press de banca', 'muscle_group' => 'chest', 'tracking_type' => TrackingType::RepsAndLoad]);
    $wePress = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercisePress->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $exercisePress->toSnapshot(),
    ]);
    subSeedCatalog('legs');

    subFakeHttp(subChatBody([
        'safety_signal_text' => null,
        'reports' => [
            [
                'exercise_name' => null, 'not_performed' => true, 'skip_reason' => 'dont_want',
                'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ],
            [
                'exercise_name' => 'Press de banca', 'not_performed' => false, 'skip_reason' => null,
                'sets' => [['reps' => 10, 'load' => 8, 'duration_seconds' => null], ['reps' => 10, 'load' => 8, 'duration_seconds' => null], ['reps' => 10, 'load' => 8, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ],
        ],
        'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Dame otro ejercicio');

    // El conflictivo (Ejercicio A, anónimo) nunca se persiste.
    expect(ExerciseLog::where('workout_exercise_id', $weA->id)->exists())->toBeFalse();
    // A queda sustituido (nunca reportado).
    expect($weA->fresh()->superseded_by_id)->not->toBeNull();

    // El reporte REAL (Press de banca, nombrado) SÍ se conserva y se persiste.
    $logPress = ExerciseLog::where('workout_exercise_id', $wePress->id)->first();
    expect($logPress)->not->toBeNull();
    expect($logPress->exerciseSets)->toHaveCount(3);
    expect($logPress->exerciseSets->first()->actual_load)->toEqual(8.0);

    // Press de banca nunca fue sustituido — el conflicto solo afectó a A.
    expect($wePress->fresh()->superseded_by_id)->toBeNull();

    // Exactamente 1 sustitución en toda la sesión.
    expect(WorkoutExercise::where('workout_session_id', $session->id)->whereNotNull('superseded_by_id')->count())->toBe(1);
});

it('REGRESSION (incidente real, sesión #48 de staging): A=front con "dont_want" accidental + substitute_exercise -> A superseded, B intacto, nunca A logged + B substituted', function () {
    $contact = subReadyContact();
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);

    // Nombres concretos (nunca "Ejercicio A/B") — "Dame otro ejercicio"
    // contiene literalmente la palabra "ejercicio", que colisionaría con
    // AMBOS nombres genéricos vía la Vía 3 (match por nombre/token) del
    // target resolver, produciendo "ambiguous" en vez de resolver por
    // frente (Vía 4) — el propio punto que este test necesita ejercitar.
    $exerciseA = Exercise::factory()->create(['name' => 'Sentadilla', 'name_es' => 'Sentadilla', 'muscle_group' => 'legs', 'tracking_type' => TrackingType::RepsAndLoad]);
    $weA = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $exerciseA->id, 'order' => 1,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $exerciseA->toSnapshot(), 'delivered_at' => now(),
    ]);
    $exerciseB = Exercise::factory()->create(['name' => 'Press de banca', 'name_es' => 'Press de banca', 'muscle_group' => 'legs', 'tracking_type' => TrackingType::RepsAndLoad]);
    $weB = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $exerciseB->id, 'order' => 2,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 3, 'prescribed_reps' => 10,
        'exercise_snapshot' => $exerciseB->toSnapshot(),
    ]);
    subSeedCatalog('legs'); // candidatos de reemplazo para A

    subFakeHttp(subChatBody([
        'safety_signal_text' => null,
        'reports' => [[
            'exercise_name' => null, 'not_performed' => true, 'skip_reason' => 'dont_want',
            'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
        'intents' => ['substitute_exercise'], 'training_reply' => null, 'requested_focus_terms' => [],
    ]));

    sendSubMessage($contact, 'Dame otro ejercicio');

    // A -> superseded, con un replacement activo.
    expect($weA->fresh()->superseded_by_id)->not->toBeNull();
    $replacementOfA = WorkoutExercise::find($weA->fresh()->superseded_by_id);
    expect($replacementOfA)->not->toBeNull();
    expect($replacementOfA->superseded_by_id)->toBeNull();

    // B completamente intacto: nunca entregado, nunca sustituido, nunca reportado.
    expect($weB->fresh()->superseded_by_id)->toBeNull();
    expect($weB->fresh()->delivered_at)->toBeNull();
    expect(ExerciseLog::where('workout_exercise_id', $weB->id)->exists())->toBeFalse();

    // A nunca quedó "logged" (dont_want) — el único efecto de este turno es
    // la sustitución.
    expect(ExerciseLog::where('workout_exercise_id', $weA->id)->exists())->toBeFalse();

    // Exactamente 1 sustitución en toda la sesión.
    expect(WorkoutExercise::where('workout_session_id', $session->id)->whereNotNull('superseded_by_id')->count())->toBe(1);
});
