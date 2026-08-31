<?php

use App\Models\ExerciseLog;
use App\Models\ExerciseSet;
use App\Models\WorkoutExercise;

it('represents a squat with sets of different reps/load, exactly as executed', function () {
    // Sentadilla: 10x40kg, 10x45kg, 8x50kg — el caso que un ExerciseLog con
    // campos escalares no podía representar sin perder información.
    $workoutExercise = WorkoutExercise::factory()->create([
        'prescribed_sets' => 3,
        'prescribed_reps' => 10,
        'prescribed_load' => 40,
    ]);

    $log = ExerciseLog::factory()->create([
        'workout_exercise_id' => $workoutExercise->id,
        'rpe' => 8,
        'note' => 'La última serie costó bastante.',
    ]);

    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'set_number' => 1, 'actual_reps' => 10, 'actual_load' => 40]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'set_number' => 2, 'actual_reps' => 10, 'actual_load' => 45]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'set_number' => 3, 'actual_reps' => 8, 'actual_load' => 50]);

    $sets = $log->fresh()->exerciseSets;

    expect($sets)->toHaveCount(3);
    expect($sets->pluck('actual_reps')->all())->toBe([10, 10, 8]);
    expect($sets->pluck('actual_load')->map(fn ($v) => (float) $v)->all())->toBe([40.0, 45.0, 50.0]);
});

it('supports time-based sets with duration instead of reps/load', function () {
    $log = ExerciseLog::factory()->create();

    $set = ExerciseSet::factory()->create([
        'exercise_log_id' => $log->id,
        'actual_reps' => null,
        'actual_load' => null,
        'actual_duration_seconds' => 45,
    ]);

    expect($set->fresh()->actual_reps)->toBeNull();
    expect($set->fresh()->actual_load)->toBeNull();
    expect($set->fresh()->actual_duration_seconds)->toBe(45);
});

it('never lets what was executed overwrite what was prescribed', function () {
    $workoutExercise = WorkoutExercise::factory()->create([
        'prescribed_sets' => 3,
        'prescribed_reps' => 10,
        'prescribed_load' => 40,
    ]);

    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $workoutExercise->id, 'rpe' => 9]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 6, 'actual_load' => 55]);

    $reloadedWorkoutExercise = $workoutExercise->fresh();

    expect((int) $reloadedWorkoutExercise->prescribed_sets)->toBe(3);
    expect((int) $reloadedWorkoutExercise->prescribed_reps)->toBe(10);
    expect((float) $reloadedWorkoutExercise->prescribed_load)->toBe(40.0);

    // Lo real vive exclusivamente en ExerciseLog/ExerciseSet.
    expect($reloadedWorkoutExercise->exerciseLog->rpe)->toBe(9);
    expect($reloadedWorkoutExercise->exerciseLog->exerciseSets->first()->actual_reps)->toBe(6);
});

it('allows a session to be partially reported — not every prescribed exercise needs a log', function () {
    $workoutExercise = WorkoutExercise::factory()->create();

    expect($workoutExercise->fresh()->exerciseLog)->toBeNull();
});
