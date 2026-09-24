<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\TrainingPreferenceClarification;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\TrainingPreferenceClarificationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingPreferenceClarification>
 */
class TrainingPreferenceClarificationFactory extends Factory
{
    protected $model = TrainingPreferenceClarification::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'dimension' => PreferenceDimension::Exercise,
            'original_candidate_term' => 'sentadillas',
            'original_text' => 'No me gustan las sentadillas.',
            'presented_options' => ['Sentadilla con banda', 'Sentadilla con peso corporal'],
            'total_matches' => 2,
            'status' => TrainingPreferenceClarificationStatus::Pending,
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => TrainingPreferenceClarificationStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function abandoned(): static
    {
        return $this->state(fn () => [
            'status' => TrainingPreferenceClarificationStatus::Abandoned,
            'abandoned_at' => now(),
        ]);
    }
}
