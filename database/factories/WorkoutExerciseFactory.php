<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkoutExercise>
 */
class WorkoutExerciseFactory extends Factory
{
    protected $model = WorkoutExercise::class;

    public function definition(): array
    {
        $exercise = Exercise::factory()->create();

        return [
            'workout_session_id' => WorkoutSession::factory(),
            'exercise_id' => $exercise->id,
            'order' => 1,
            'prescribed_sets' => 3,
            'prescribed_reps' => 10,
            'prescribed_load' => null,
            'prescribed_duration_seconds' => null,
            'rest_seconds' => 60,
            'exercise_snapshot' => $exercise->toSnapshot(),
        ];
    }
}
