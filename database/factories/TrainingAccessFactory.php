<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\TrainingAccess;
use App\Training\Enums\TrainingAccessStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingAccess>
 */
class TrainingAccessFactory extends Factory
{
    protected $model = TrainingAccess::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'status' => TrainingAccessStatus::Active,
            'granted_at' => now(),
            'expires_at' => now()->addMonth(),
            'granted_by' => 'manual:superadmin',
            'notes' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => TrainingAccessStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => TrainingAccessStatus::Revoked]);
    }
}
