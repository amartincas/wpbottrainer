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

    /**
     * Hito B2 — `superseded_by_id` queda `null` por defecto: en un test
     * real, se asigna después de crear la sesión que la reemplaza (mismo
     * patrón que `ReplaceWorkoutSessionService`, que tampoco lo conoce de
     * antemano).
     */
    public function superseded(): static
    {
        return $this->state(fn () => ['status' => WorkoutSessionStatus::Superseded]);
    }
}
