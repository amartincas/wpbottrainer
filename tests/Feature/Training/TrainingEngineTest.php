<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\ExerciseSet;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrackingType;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessDeniedException;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrainingHistoryContextProvider;
use Illuminate\Support\Facades\Log;

function makeReadyContact(array $profileOverrides = []): Contact
{
    $contact = Contact::factory()->create();

    TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'split_type' => SplitType::FullBody,
    ], $profileOverrides));

    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

function trainingEngine(): TrainingEngine
{
    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);

    return new TrainingEngine(
        new TrainingAccessGate,
        $safetyResolver,
        new TrainingHistoryContextProvider($safetyResolver),
        new ProgressionEvaluator,
    );
}

it('blocks generation with no_access when there is no TrainingAccess', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    trainingEngine()->decideNextSession($contact->fresh());
})->throws(TrainingAccessDeniedException::class);

it('blocks generation when the profile is flagged for safety review', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->flaggedForSafetyReview()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    try {
        trainingEngine()->decideNextSession($contact->fresh());
        expect(false)->toBeTrue('Expected TrainingAccessDeniedException to be thrown.');
    } catch (TrainingAccessDeniedException $e) {
        expect($e->reason)->toBe('safety_flagged');
    }
});

it('is idempotent — returns the existing pending session instead of generating a new one', function () {
    $contact = makeReadyContact();

    $pending = WorkoutSession::factory()->create([
        'contact_id' => $contact->id,
        'status' => WorkoutSessionStatus::Scheduled,
    ]);

    $result = trainingEngine()->decideNextSession($contact);

    expect($result->id)->toBe($pending->id);
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
});

it('generates a session with prescribed exercises snapshotted from the current catalog', function () {
    // Hito 8.4: goal fijado explícitamente — GOAL_DEFAULTS varía sets/reps
    // por objetivo, así que esta aserción de "prescripción conservadora por
    // defecto" ya no puede depender del goal aleatorio del factory.
    $contact = makeReadyContact(['goal' => TrainingGoal::GeneralFitness]);

    $chest = Exercise::factory()->create(['muscle_group' => 'chest', 'name' => 'Press de banca']);
    $legs = Exercise::factory()->create(['muscle_group' => 'legs', 'name' => 'Sentadilla']);
    $back = Exercise::factory()->create(['muscle_group' => 'back', 'name' => 'Remo']);

    $session = trainingEngine()->decideNextSession($contact);

    expect($session->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect($session->generated_by)->toBe('training_engine');
    expect($session->workoutExercises)->toHaveCount(3);

    $names = $session->workoutExercises->pluck('exercise_snapshot.name')->sort()->values()->all();
    expect($names)->toBe(['Press de banca', 'Remo', 'Sentadilla']);

    // Sin historial previo: prescripción conservadora por defecto.
    $session->workoutExercises->each(function (WorkoutExercise $we) {
        expect($we->prescribed_sets)->toBe(3);
        expect($we->prescribed_reps)->toBe(10);
        expect($we->prescribed_load)->toBeNull();
    });
});

it('never selects an exercise whose contraindications match the profile restrictions', function () {
    $contact = makeReadyContact(['restrictions' => ['knee']]);

    $restricted = Exercise::factory()->create(['muscle_group' => 'legs', 'contraindications' => ['knee']]);
    $safe = Exercise::factory()->create(['muscle_group' => 'legs', 'contraindications' => []]);

    $session = trainingEngine()->decideNextSession($contact);

    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->not->toContain($restricted->id);
    expect($selectedIds)->toContain($safe->id);
});

it('never selects an exercise that needs equipment the profile does not have', function () {
    $contact = makeReadyContact(['available_equipment' => []]);

    $needsBarbell = Exercise::factory()->create(['muscle_group' => 'chest', 'equipment_needed' => ['barbell']]);
    $bodyweight = Exercise::factory()->create(['muscle_group' => 'chest', 'equipment_needed' => []]);

    $session = trainingEngine()->decideNextSession($contact);

    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->not->toContain($needsBarbell->id);
    expect($selectedIds)->toContain($bodyweight->id);
});

it('includes an exercise that needs equipment the profile does have', function () {
    // Hito 9.0: training_location fijado explícitamente — es aleatorio por
    // defecto en el factory, y "outdoor" excluiría este mismo ejercicio por
    // una razón completamente distinta a la que este test verifica.
    $contact = makeReadyContact(['available_equipment' => ['barbell'], 'training_location' => TrainingLocation::Gym]);

    $needsBarbell = Exercise::factory()->create(['muscle_group' => 'chest', 'equipment_needed' => ['barbell']]);

    $session = trainingEngine()->decideNextSession($contact);

    expect($session->workoutExercises->pluck('exercise_id')->all())->toContain($needsBarbell->id);
});

it('increases load for the next session when the last reported RPE was manageable', function () {
    $contact = makeReadyContact();
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    // Bloque 8 (D050/D051): ProgressionEvaluator exige al menos 2
    // ejecuciones `Performed` como evidencia mínima antes de poder devolver
    // `progress` — una sola ejecución nunca es suficiente (ver D050, gate
    // de evidencia). Se agrega una ejecución anterior legítima para
    // conservar la intención original del test (RPE manejable + carga en
    // aumento -> progresa), no para forzar el resultado.
    // prescribed_sets = 1 (no 3): cada ejecución solo registra 1 set real —
    // D050 exige que los sets EJECUTADOS cubran los prescritos para poder
    // considerar "cumplidas" las reps (fewer_sets_than_prescribed); con
    // prescribed_sets=3 y un único set real, la evidencia habría sido
    // insuficiente para progresar por una razón distinta a la que este
    // test verifica (RPE + carga), así que se alinean para no interferir.
    $olderSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
    $olderWorkoutExercise = WorkoutExercise::factory()->create([
        'workout_session_id' => $olderSession->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1,
        'prescribed_reps' => 10,
        'prescribed_load' => 38,
    ]);
    $olderLog = ExerciseLog::factory()->create(['workout_exercise_id' => $olderWorkoutExercise->id, 'rpe' => 6, 'logged_at' => now()->subDays(2)]);
    ExerciseSet::factory()->create(['exercise_log_id' => $olderLog->id, 'actual_reps' => 10, 'actual_load' => 38]);

    $pastSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    $pastWorkoutExercise = WorkoutExercise::factory()->create([
        'workout_session_id' => $pastSession->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1,
        'prescribed_reps' => 10,
        'prescribed_load' => 40,
    ]);
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $pastWorkoutExercise->id, 'rpe' => 6, 'logged_at' => now()->subDay()]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 10, 'actual_load' => 42]);

    $session = trainingEngine()->decideNextSession($contact);
    $newWorkoutExercise = $session->workoutExercises->firstWhere('exercise_id', $exercise->id);

    expect((float) $newWorkoutExercise->prescribed_load)->toBe(44.5);
});

it('does not increase load when the last reported RPE was too high', function () {
    $contact = makeReadyContact();
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    $pastSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $pastWorkoutExercise = WorkoutExercise::factory()->create([
        'workout_session_id' => $pastSession->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_load' => 40,
    ]);
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $pastWorkoutExercise->id, 'rpe' => 9]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_load' => 42]);

    $session = trainingEngine()->decideNextSession($contact);
    $newWorkoutExercise = $session->workoutExercises->firstWhere('exercise_id', $exercise->id);

    expect((float) $newWorkoutExercise->prescribed_load)->toBe(42.0);
});

it('progresses duration instead of load/reps for time-based exercises', function () {
    $contact = makeReadyContact();
    $exercise = Exercise::factory()->timeBased()->create(['muscle_group' => 'core']);

    // Bloque 8 (D050/D051): mismo motivo que el test de carga — se agrega
    // una ejecución anterior legítima para satisfacer el nuevo gate de
    // evidencia mínima (>=2 ejecuciones) sin alterar la intención original
    // del test (RPE manejable + duración en aumento -> progresa).
    $olderSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
    $olderWorkoutExercise = WorkoutExercise::factory()->create([
        'workout_session_id' => $olderSession->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_duration_seconds' => 25,
        'prescribed_reps' => null,
        'prescribed_load' => null,
    ]);
    $olderLog = ExerciseLog::factory()->create(['workout_exercise_id' => $olderWorkoutExercise->id, 'rpe' => 5, 'logged_at' => now()->subDays(2)]);
    ExerciseSet::factory()->create([
        'exercise_log_id' => $olderLog->id,
        'actual_reps' => null,
        'actual_load' => null,
        'actual_duration_seconds' => 25,
    ]);

    $pastSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    $pastWorkoutExercise = WorkoutExercise::factory()->create([
        'workout_session_id' => $pastSession->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_duration_seconds' => 30,
        'prescribed_reps' => null,
        'prescribed_load' => null,
    ]);
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $pastWorkoutExercise->id, 'rpe' => 5, 'logged_at' => now()->subDay()]);
    ExerciseSet::factory()->create([
        'exercise_log_id' => $log->id,
        'actual_reps' => null,
        'actual_load' => null,
        'actual_duration_seconds' => 30,
    ]);

    $session = trainingEngine()->decideNextSession($contact);
    $newWorkoutExercise = $session->workoutExercises->firstWhere('exercise_id', $exercise->id);

    expect($newWorkoutExercise->prescribed_duration_seconds)->toBe(40);
    expect($newWorkoutExercise->prescribed_reps)->toBeNull();
    expect($newWorkoutExercise->prescribed_load)->toBeNull();
});

it('avoids repeating the exact same focus as the immediately preceding completed session', function () {
    $contact = makeReadyContact(['split_type' => SplitType::PushPullLegs]);

    // Un ejercicio disponible para cada grupo posible, para que la selección
    // no falle por falta de catálogo sin importar qué foco decida el Engine.
    foreach (['arms', 'chest', 'shoulders', 'back', 'core', 'legs'] as $group) {
        Exercise::factory()->create(['muscle_group' => $group]);
    }

    $first = trainingEngine()->decideNextSession($contact);
    $firstFocus = $first->workoutExercises->pluck('exercise_snapshot.muscle_group')->sort()->values()->implode(',');

    $first->update(['status' => WorkoutSessionStatus::Completed, 'completed_at' => now()]);

    $second = trainingEngine()->decideNextSession($contact->fresh());
    $secondFocus = $second->workoutExercises->pluck('exercise_snapshot.muscle_group')->sort()->values()->implode(',');

    expect($secondFocus)->not->toBe($firstFocus);
});

it('requires a TrainingProfile to exist before generating anything', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    trainingEngine()->decideNextSession($contact->fresh());
})->throws(RuntimeException::class);

// ─────────────────────────────────────────────────────────────────────────
// Hito 8.4 — objetivos específicos y personalización real por foco muscular.
// ─────────────────────────────────────────────────────────────────────────

it('prioritizes an exercise matching a simple primary_focus over the general pool', function () {
    $contact = makeReadyContact(['primary_focus' => [MuscleFocus::Glutes->value]]);

    $glutes = Exercise::factory()->withPrimaryMuscle(MuscleFocus::Glutes)->create(['muscle_group' => 'legs']);
    Exercise::factory()->create(['muscle_group' => 'legs']);
    Exercise::factory()->create(['muscle_group' => 'core']);

    $session = trainingEngine()->decideNextSession($contact);

    expect($session->workoutExercises->pluck('exercise_id')->all())->toContain($glutes->id);
});

it('resolves a compound focus ("piernas") into every matching primary_muscle, not just one', function () {
    $contact = makeReadyContact([
        'primary_focus' => [MuscleFocus::Quads->value, MuscleFocus::Hamstrings->value, MuscleFocus::Glutes->value, MuscleFocus::Calves->value],
    ]);

    $quads = Exercise::factory()->withPrimaryMuscle(MuscleFocus::Quads)->create(['muscle_group' => 'legs']);
    $hamstrings = Exercise::factory()->withPrimaryMuscle(MuscleFocus::Hamstrings)->create(['muscle_group' => 'legs']);
    $glutes = Exercise::factory()->withPrimaryMuscle(MuscleFocus::Glutes)->create(['muscle_group' => 'legs']);
    // Ejercicio ajeno al foco, disponible por si el pool de foco fallara.
    Exercise::factory()->create(['muscle_group' => 'chest']);

    $session = trainingEngine()->decideNextSession($contact);

    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();
    expect($selectedIds)->toEqualCanonicalizing([$quads->id, $hamstrings->id, $glutes->id]);
});

it('combines focus and goal: a focus exercise is selected AND prescribed with that goal\'s starting defaults', function () {
    $contact = makeReadyContact([
        'primary_focus' => [MuscleFocus::Chest->value],
        'goal' => TrainingGoal::BuildMuscle,
    ]);

    $chest = Exercise::factory()->withPrimaryMuscle(MuscleFocus::Chest)->create(['muscle_group' => 'chest']);
    Exercise::factory()->create(['muscle_group' => 'legs']);
    Exercise::factory()->create(['muscle_group' => 'back']);

    $session = trainingEngine()->decideNextSession($contact);
    $prescribed = $session->workoutExercises->firstWhere('exercise_id', $chest->id);

    expect($prescribed)->not->toBeNull();
    // build_muscle: sets=4, rest_seconds=90 (GOAL_DEFAULTS, sin historial previo).
    expect($prescribed->prescribed_sets)->toBe(4);
    expect($prescribed->prescribed_reps)->toBe(10);
    expect($prescribed->rest_seconds)->toBe(90);
});

it('logs TRAINING_FOCUS_FALLBACK when the catalog cannot fill the minimum focus guarantee', function () {
    Log::spy();

    $contact = makeReadyContact(['primary_focus' => [MuscleFocus::Glutes->value]]);

    // Ningún ejercicio del catálogo tiene primary_muscle/secondary_muscles
    // en glutes — el foco declarado no puede satisfacerse con este catálogo.
    Exercise::factory()->create(['muscle_group' => 'chest']);
    Exercise::factory()->create(['muscle_group' => 'back']);
    Exercise::factory()->create(['muscle_group' => 'core']);

    trainingEngine()->decideNextSession($contact);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message) => $message === 'TRAINING_FOCUS_FALLBACK')
        ->once();
});

it('guarantees at least a majority of the session comes from the focus pool when the catalog supports it', function () {
    $contact = makeReadyContact(['primary_focus' => [MuscleFocus::Back->value]]);

    $back1 = Exercise::factory()->withPrimaryMuscle(MuscleFocus::Back)->create(['muscle_group' => 'back']);
    $back2 = Exercise::factory()->withPrimaryMuscle(MuscleFocus::Back)->create(['muscle_group' => 'back']);
    Exercise::factory()->create(['muscle_group' => 'chest']);
    Exercise::factory()->create(['muscle_group' => 'legs']);

    $session = trainingEngine()->decideNextSession($contact);

    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();
    $focusCount = collect([$back1->id, $back2->id])->filter(fn ($id) => in_array($id, $selectedIds, true))->count();

    // ceil(EXERCISES_PER_SESSION / 2) = 2 de 3.
    expect($focusCount)->toBeGreaterThanOrEqual(2);
});

it('deprioritizes an exercise used in the immediately preceding session over an equally-good alternative', function () {
    $contact = makeReadyContact(['experience_level' => ExperienceLevel::Beginner]);

    $used = Exercise::factory()->create(['muscle_group' => 'core', 'difficulty_level' => 'beginner']);
    $freshA = Exercise::factory()->create(['muscle_group' => 'core', 'difficulty_level' => 'beginner']);
    $freshB = Exercise::factory()->create(['muscle_group' => 'core', 'difficulty_level' => 'beginner']);
    $freshC = Exercise::factory()->create(['muscle_group' => 'core', 'difficulty_level' => 'beginner']);

    $pastSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create([
        'workout_session_id' => $pastSession->id,
        'exercise_id' => $used->id,
        'exercise_snapshot' => $used->toSnapshot(),
    ]);

    $session = trainingEngine()->decideNextSession($contact->fresh());
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->not->toContain($used->id);
    expect($selectedIds)->toEqualCanonicalizing([$freshA->id, $freshB->id, $freshC->id]);
});

it('prioritizes exercises matching the profile experience_level over a difficulty mismatch', function () {
    $contact = makeReadyContact(['experience_level' => ExperienceLevel::Intermediate]);

    $matchA = Exercise::factory()->create(['muscle_group' => 'legs', 'difficulty_level' => 'intermediate']);
    $matchB = Exercise::factory()->create(['muscle_group' => 'legs', 'difficulty_level' => 'intermediate']);
    $matchC = Exercise::factory()->create(['muscle_group' => 'legs', 'difficulty_level' => 'intermediate']);
    Exercise::factory()->create(['muscle_group' => 'legs', 'difficulty_level' => 'beginner']);

    $session = trainingEngine()->decideNextSession($contact);

    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();
    expect($selectedIds)->toEqualCanonicalizing([$matchA->id, $matchB->id, $matchC->id]);
});

it('resolves an effective "has everything" equipment set for a gym profile without requiring an enumerated list', function () {
    // Validación explícita pedida tras la revisión de Hito 8.4: un perfil de
    // gimnasio que declaró "tengo de todo" NO enumeró ningún equipo
    // (available_equipment=[]) — la elegibilidad debe usar ese equipo
    // EFECTIVO (equipment_fully_equipped=true), nunca exigir la lista.
    $contact = makeReadyContact([
        'training_location' => TrainingLocation::Gym,
        'equipment_fully_equipped' => true,
        'available_equipment' => [],
    ]);

    $needsBarbell = Exercise::factory()->create(['muscle_group' => 'chest', 'equipment_needed' => ['barbell']]);
    $needsMachine = Exercise::factory()->create(['muscle_group' => 'back', 'equipment_needed' => ['cable_machine']]);
    $needsBands = Exercise::factory()->create(['muscle_group' => 'legs', 'equipment_needed' => ['resistance_bands']]);

    $session = trainingEngine()->decideNextSession($contact);
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    // Los 3 exigen equipo DISTINTO, ninguno enumerado — los 3 son elegibles
    // porque el equipo efectivo es "todo", no una lista concreta.
    expect($selectedIds)->toEqualCanonicalizing([$needsBarbell->id, $needsMachine->id, $needsBands->id]);
});

it('requires explicit available_equipment enumeration when equipment_fully_equipped is false, even for the same gym profile', function () {
    // Caso contrario, mismo training_location=gym: sin la declaración amplia,
    // la elegibilidad depende ÚNICAMENTE de lo enumerado explícitamente.
    $contact = makeReadyContact([
        'training_location' => TrainingLocation::Gym,
        'equipment_fully_equipped' => false,
        'available_equipment' => ['dumbbells'],
    ]);

    $needsDumbbells = Exercise::factory()->create(['muscle_group' => 'chest', 'equipment_needed' => ['dumbbells']]);
    $needsBarbell = Exercise::factory()->create(['muscle_group' => 'back', 'equipment_needed' => ['barbell']]);
    $bodyweight = Exercise::factory()->create(['muscle_group' => 'legs', 'equipment_needed' => []]);

    $session = trainingEngine()->decideNextSession($contact);
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->toContain($needsDumbbells->id); // enumerado explícitamente
    expect($selectedIds)->toContain($bodyweight->id);     // no requiere equipo
    expect($selectedIds)->not->toContain($needsBarbell->id); // NO enumerado, y no hay "de todo"
});

it('produces genuinely different sessions and demonstrates WHICH variable causes each difference — A/B/C', function () {
    // Perfiles deliberadamente distintos en TODAS las variables relevantes,
    // para poder atribuir cada diferencia de salida a su causa exacta, no
    // solo mostrar que "son distintas" (validación explícita post-revisión).
    //
    // A: pérdida de peso, prioriza glúteos, principiante, full_body.
    $contactA = makeReadyContact([
        'goal' => TrainingGoal::LoseWeight,
        'primary_focus' => [MuscleFocus::Glutes->value],
        'experience_level' => ExperienceLevel::Beginner,
        'training_location' => TrainingLocation::Home,
        'sessions_per_week' => 3,
        'split_type' => SplitType::FullBody,
    ]);
    // B: ganancia muscular, prioriza pecho, avanzado, full_body (mismo
    // split_type que A a propósito — así cualquier diferencia entre A y B
    // en el pool general NO puede atribuirse a split_type, solo a foco/nivel).
    $contactB = makeReadyContact([
        'goal' => TrainingGoal::BuildMuscle,
        'primary_focus' => [MuscleFocus::Chest->value],
        'experience_level' => ExperienceLevel::Advanced,
        'training_location' => TrainingLocation::Gym,
        'sessions_per_week' => 5,
        'split_type' => SplitType::FullBody,
    ]);
    // C: condición general, SIN foco declarado, intermedio, push_pull_legs
    // (único con split_type distinto — para aislar su efecto causal).
    $contactC = makeReadyContact([
        'goal' => TrainingGoal::GeneralFitness,
        'primary_focus' => [],
        'experience_level' => ExperienceLevel::Intermediate,
        'training_location' => TrainingLocation::Gym,
        'sessions_per_week' => 4,
        'split_type' => SplitType::PushPullLegs,
    ]);

    // Catálogo: cada ejercicio existe para probar UNA causa específica.
    $glutesExercise = Exercise::factory()->withPrimaryMuscle(MuscleFocus::Glutes)->create(['muscle_group' => 'legs', 'difficulty_level' => 'beginner']);
    $chestFocusExercise = Exercise::factory()->withPrimaryMuscle(MuscleFocus::Chest)->create(['muscle_group' => 'chest', 'difficulty_level' => 'advanced']);
    // Pool general "intermedio": matchea el nivel de C exactamente, y el de
    // A/B solo parcialmente (1 nivel de diferencia) — nunca el mejor match
    // para A ni B, así que su presencia/ausencia es atribuible a nivel.
    $generalArms = Exercise::factory()->create(['muscle_group' => 'arms', 'difficulty_level' => 'intermediate']);
    $generalShoulders = Exercise::factory()->create(['muscle_group' => 'shoulders', 'difficulty_level' => 'intermediate']);
    $generalChest = Exercise::factory()->create(['muscle_group' => 'chest', 'difficulty_level' => 'intermediate']);
    // Solo elegibles por muscle_group para full_body (A/B) — PushPullLegs
    // restringe el pool general de C a solo {arms, chest, shoulders} en la
    // primera sesión (ver TrainingEngine::mostNeglectedFocus()), así que
    // estos dos NUNCA deben aparecer en la sesión de C pese a ser elegibles
    // y de nivel intermedio (igual que los 3 de arriba) — la única
    // diferencia es su muscle_group frente al split_type de C.
    $legsGeneralOnly = Exercise::factory()->create(['muscle_group' => 'legs', 'difficulty_level' => 'intermediate']);
    $backGeneralOnly = Exercise::factory()->create(['muscle_group' => 'back', 'difficulty_level' => 'intermediate']);

    $sessionA = trainingEngine()->decideNextSession($contactA);
    $sessionB = trainingEngine()->decideNextSession($contactB);
    $sessionC = trainingEngine()->decideNextSession($contactC);

    // ── 1. Trazabilidad de los datos de entrada por perfil (integridad) ──
    $contactA->trainingProfile->refresh();
    $contactB->trainingProfile->refresh();
    $contactC->trainingProfile->refresh();

    expect($contactA->trainingProfile->goal)->toBe(TrainingGoal::LoseWeight);
    expect($contactA->trainingProfile->experience_level)->toBe(ExperienceLevel::Beginner);
    expect($contactA->trainingProfile->primary_focus)->toBe(['glutes']);
    expect($contactA->trainingProfile->split_type)->toBe(SplitType::FullBody);
    expect($contactA->trainingProfile->training_location)->toBe(TrainingLocation::Home);
    expect($contactA->trainingProfile->sessions_per_week)->toBe(3);

    expect($contactB->trainingProfile->goal)->toBe(TrainingGoal::BuildMuscle);
    expect($contactB->trainingProfile->experience_level)->toBe(ExperienceLevel::Advanced);
    expect($contactB->trainingProfile->primary_focus)->toBe(['chest']);
    expect($contactB->trainingProfile->split_type)->toBe(SplitType::FullBody);
    expect($contactB->trainingProfile->training_location)->toBe(TrainingLocation::Gym);
    expect($contactB->trainingProfile->sessions_per_week)->toBe(5);

    expect($contactC->trainingProfile->goal)->toBe(TrainingGoal::GeneralFitness);
    expect($contactC->trainingProfile->experience_level)->toBe(ExperienceLevel::Intermediate);
    expect($contactC->trainingProfile->primary_focus)->toBe([]);
    expect($contactC->trainingProfile->split_type)->toBe(SplitType::PushPullLegs);
    expect($contactC->trainingProfile->training_location)->toBe(TrainingLocation::Gym);
    expect($contactC->trainingProfile->sessions_per_week)->toBe(4);

    // NOTA: training_location y sessions_per_week se capturan y persisten
    // correctamente (arriba), pero HOY `TrainingEngine` no los consume en
    // absoluto — ni en elegibilidad ni en prescripción (ver docs/DECISIONS.md
    // D034). Documentado explícitamente para no sugerir una causalidad que
    // el código todavía no implementa.

    // ── 2. primary_focus causa la inclusión del ejercicio de foco ──
    $idsA = $sessionA->workoutExercises->pluck('exercise_id')->all();
    $idsB = $sessionB->workoutExercises->pluck('exercise_id')->all();
    $idsC = $sessionC->workoutExercises->pluck('exercise_id')->all();

    expect($idsA)->toContain($glutesExercise->id);       // primary_focus=[glutes] → A
    expect($idsB)->toContain($chestFocusExercise->id);   // primary_focus=[chest]  → B
    expect($idsC)->not->toContain($glutesExercise->id);  // C no declaró foco
    expect($idsC)->not->toContain($chestFocusExercise->id);

    // ── 3. experience_level causa la exclusión del ejercicio de foco ajeno,
    //        incluso siendo elegible y del mismo split_type (full_body) ──
    // chestFocusExercise es 'advanced' — para A (beginner) pierde contra los
    // 3 candidatos 'intermediate' (1 nivel de diferencia vs. 2) y por lo
    // tanto NUNCA se cuela en el pool general de A.
    expect($idsA)->not->toContain($chestFocusExercise->id);
    // Simétricamente, glutesExercise es 'beginner' — para B (advanced)
    // pierde contra los mismos 3 candidatos 'intermediate'.
    expect($idsB)->not->toContain($glutesExercise->id);

    // ── 4. split_type causa qué grupos musculares entran al pool general —
    //        C es el único con push_pull_legs, y su primera sesión (sin
    //        historial) queda restringida al primer grupo de esa rotación
    //        (arms,chest,shoulders) — un resultado 100% determinista ──
    expect($idsC)->toEqualCanonicalizing([$generalArms->id, $generalShoulders->id, $generalChest->id]);
    // legs/back son elegibles y de nivel intermedio (igual que los 3 de
    // arriba) pero quedan fuera SOLO por el split_type de C.
    expect($idsC)->not->toContain($legsGeneralOnly->id);
    expect($idsC)->not->toContain($backGeneralOnly->id);

    // ── 5. goal causa la prescripción (GOAL_DEFAULTS), sin historial previo ──
    $prescribedA = $sessionA->workoutExercises->firstWhere('exercise_id', $glutesExercise->id);
    $prescribedB = $sessionB->workoutExercises->firstWhere('exercise_id', $chestFocusExercise->id);
    $prescribedCArms = $sessionC->workoutExercises->firstWhere('exercise_id', $generalArms->id);

    expect($prescribedA->prescribed_sets)->toBe(3);   // lose_weight
    expect($prescribedA->prescribed_reps)->toBe(15);  // lose_weight
    expect($prescribedA->rest_seconds)->toBe(30);     // lose_weight

    expect($prescribedB->prescribed_sets)->toBe(4);   // build_muscle
    expect($prescribedB->prescribed_reps)->toBe(10);  // build_muscle
    expect($prescribedB->rest_seconds)->toBe(90);     // build_muscle

    expect($prescribedCArms->prescribed_sets)->toBe(3);  // general_fitness
    expect($prescribedCArms->prescribed_reps)->toBe(10); // general_fitness
    expect($prescribedCArms->rest_seconds)->toBe(60);    // general_fitness

    // ── 6. Conclusión: las 3 combinaciones de ejercicios son distintas, y
    //        cada diferencia ya quedó atribuida a una causa concreta arriba
    //        (foco, nivel o split_type) — no es una diferencia accidental ──
    expect(collect($idsA)->sort()->values()->all())->not->toBe(collect($idsB)->sort()->values()->all());
    expect(collect($idsA)->sort()->values()->all())->not->toBe(collect($idsC)->sort()->values()->all());
    expect(collect($idsB)->sort()->values()->all())->not->toBe(collect($idsC)->sort()->values()->all());
});

// ─────────────────────────────────────────────────────────────────────────
// Hito 9.0 — cierre de consumidores reales de TrainingProfile.
// ─────────────────────────────────────────────────────────────────────────

it('restricts an outdoor profile to no-equipment exercises, even if the profile declares owning equipment', function () {
    // Lo que el usuario POSEE (equipment_fully_equipped=true) no es lo
    // mismo que lo que tiene consigo entrenando al aire libre.
    $contact = makeReadyContact([
        'training_location' => TrainingLocation::Outdoor,
        'equipment_fully_equipped' => true,
        'available_equipment' => [],
    ]);

    $needsBarbell = Exercise::factory()->create(['muscle_group' => 'chest', 'equipment_needed' => ['barbell']]);
    $bodyweight = Exercise::factory()->create(['muscle_group' => 'legs', 'equipment_needed' => []]);

    $session = trainingEngine()->decideNextSession($contact);
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->not->toContain($needsBarbell->id);
    expect($selectedIds)->toContain($bodyweight->id);
});

it('does not restrict a gym or home profile the same way — outdoor is the only location with a hard equipment consequence', function () {
    $contact = makeReadyContact([
        'training_location' => TrainingLocation::Gym,
        'available_equipment' => ['barbell'],
        'equipment_fully_equipped' => false,
    ]);

    $needsBarbell = Exercise::factory()->create(['muscle_group' => 'chest', 'equipment_needed' => ['barbell']]);

    $session = trainingEngine()->decideNextSession($contact);

    expect($session->workoutExercises->pluck('exercise_id')->all())->toContain($needsBarbell->id);
});

it('prioritizes an exercise matching only secondary_focus over the general pool, with no primary_focus match available', function () {
    // Aísla secondary_focus: primary_focus=[] (sin candidatos posibles en
    // ese nivel, por diseño), secondary_focus=[chest] es la única señal de
    // foco activa. Más candidatos generales que cupos, para probar que el
    // de secondary_focus SIEMPRE gana por su nivel, no por casualidad.
    $contact = makeReadyContact(['primary_focus' => [], 'secondary_focus' => [MuscleFocus::Chest->value]]);

    $secondaryFocusExercise = Exercise::factory()->withPrimaryMuscle(MuscleFocus::Chest)->create(['muscle_group' => 'chest']);
    Exercise::factory()->create(['muscle_group' => 'legs']);
    Exercise::factory()->create(['muscle_group' => 'back']);
    Exercise::factory()->create(['muscle_group' => 'core']);

    $session = trainingEngine()->decideNextSession($contact);

    expect($session->workoutExercises->pluck('exercise_id')->all())->toContain($secondaryFocusExercise->id);
});

// ─────────────────────────────────────────────────────────────────────────
// Hito 9.2 — la técnica de ejecución es presentación pura: no debe influir
// en absoluto en qué ejercicio elige TrainingEngine.
// ─────────────────────────────────────────────────────────────────────────

it('never lets rich technique content override a real ranking criterion (difficulty match) when there is genuine competition for slots', function () {
    $contact = makeReadyContact(['experience_level' => ExperienceLevel::Intermediate]);

    // 4 candidatos elegibles para solo 3 cupos — hay competencia real.
    // A/B/D coinciden con el nivel del perfil (rank 0); C NO coincide
    // (rank 1) pero tiene MÁS técnica que cualquiera de los otros tres.
    // Si la técnica influyera en el ranking, C podría desplazar a alguno
    // de los otros — esta prueba confirma que nunca ocurre.
    $matchA = Exercise::factory()->create(['muscle_group' => 'chest', 'difficulty_level' => 'intermediate']);
    $matchB = Exercise::factory()->create(['muscle_group' => 'chest', 'difficulty_level' => 'intermediate']);
    $matchD = Exercise::factory()->create(['muscle_group' => 'chest', 'difficulty_level' => 'intermediate']);
    $mismatchButRichTechnique = Exercise::factory()->create([
        'muscle_group' => 'chest',
        'difficulty_level' => 'advanced', // no coincide con el perfil (intermediate)
        'instructions' => ['Paso 1', 'Paso 2', 'Paso 3'],
        'important_points' => ['Punto clave 1', 'Punto clave 2'],
        'common_mistakes' => ['Error 1', 'Error 2'],
        'breathing_cue' => 'Inhala al bajar, exhala al subir',
    ]);

    $session = trainingEngine()->decideNextSession($contact);
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->toEqualCanonicalizing([$matchA->id, $matchB->id, $matchD->id]);
    expect($selectedIds)->not->toContain($mismatchButRichTechnique->id);
});
