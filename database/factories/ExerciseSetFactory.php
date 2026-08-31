<?php

namespace Database\Factories;

use App\Models\ExerciseLog;
use App\Models\ExerciseSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExerciseSet>
 */
class ExerciseSetFactory extends Factory
{
    protected $model = ExerciseSet::class;

    public function definition(): array
    {
        return [
            'exercise_log_id' => ExerciseLog::factory(),
            'set_number' => 1,
            'actual_reps' => 10,
            'actual_load' => 40,
            'actual_duration_seconds' => null,
        ];
    }
}
