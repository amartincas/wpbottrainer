<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkoutSession>
 */
class WorkoutSessionFactory extends Factory
{
    protected $model = WorkoutSession::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'status' => WorkoutSessionStatus::Scheduled,
            'scheduled_at' => now(),
            'completed_at' => null,
            'generated_by' => 'training_engine',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => WorkoutSessionStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn () => ['status' => WorkoutSessionStatus::Skipped]);
    }
}
