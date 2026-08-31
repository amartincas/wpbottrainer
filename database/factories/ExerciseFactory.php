<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Training\Enums\TrackingType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Exercise>
 */
class ExerciseFactory extends Factory
{
    protected $model = Exercise::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 1000000),
            'instructions' => fake()->paragraph(),
            'video_url' => 'https://videos.example.test/'.Str::slug($name).'.mp4',
            'muscle_group' => fake()->randomElement(['chest', 'back', 'legs', 'shoulders', 'arms', 'core']),
            'equipment_needed' => [],
            'difficulty_level' => fake()->randomElement(['beginner', 'intermediate', 'advanced']),
            'contraindications' => [],
            'tracking_type' => TrackingType::RepsAndLoad,
            'is_active' => true,
        ];
    }

    public function timeBased(): static
    {
        return $this->state(fn () => ['tracking_type' => TrackingType::TimeBased]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
