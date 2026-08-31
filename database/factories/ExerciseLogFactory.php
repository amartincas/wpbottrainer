<?php

namespace Database\Factories;

use App\Models\ExerciseLog;
use App\Models\WorkoutExercise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExerciseLog>
 */
class ExerciseLogFactory extends Factory
{
    protected $model = ExerciseLog::class;

    public function definition(): array
    {
        return [
            'workout_exercise_id' => WorkoutExercise::factory(),
            'rpe' => fake()->numberBetween(5, 9),
            'note' => null,
            'logged_at' => now(),
        ];
    }
}
