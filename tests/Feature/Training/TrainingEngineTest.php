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
use App\Training\Enums\SplitType;
use App\Training\Enums\TrackingType;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\TrainingAccessDeniedException;
use App\Training\Support\TrainingAccessGate;

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
    return new TrainingEngine(new TrainingAccessGate);
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
    $contact = makeReadyContact();

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
    $contact = makeReadyContact(['available_equipment' => ['barbell']]);

    $needsBarbell = Exercise::factory()->create(['muscle_group' => 'chest', 'equipment_needed' => ['barbell']]);

    $session = trainingEngine()->decideNextSession($contact);

    expect($session->workoutExercises->pluck('exercise_id')->all())->toContain($needsBarbell->id);
});

it('increases load for the next session when the last reported RPE was manageable', function () {
    $contact = makeReadyContact();
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    $pastSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $pastWorkoutExercise = WorkoutExercise::factory()->create([
        'workout_session_id' => $pastSession->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 3,
        'prescribed_reps' => 10,
        'prescribed_load' => 40,
    ]);
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $pastWorkoutExercise->id, 'rpe' => 6]);
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

    $pastSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $pastWorkoutExercise = WorkoutExercise::factory()->create([
        'workout_session_id' => $pastSession->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_duration_seconds' => 30,
        'prescribed_reps' => null,
        'prescribed_load' => null,
    ]);
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $pastWorkoutExercise->id, 'rpe' => 5]);
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
