<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\TrainingPreference;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\PreferenceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingPreference>
 */
class TrainingPreferenceFactory extends Factory
{
    protected $model = TrainingPreference::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'dimension' => PreferenceDimension::Equipment,
            'exercise_id' => null,
            'equipment_value' => 'dumbbells',
            'preference_key' => 'equipment:dumbbells',
            'status' => PreferenceStatus::Active,
            'original_text' => 'No me gusta usar mancuernas.',
            'created_at' => now(),
            'revoked_at' => null,
            'reactivated_at' => null,
        ];
    }

    public function forExercise(int $exerciseId, string $originalText = 'No me gustan los burpees.'): static
    {
        return $this->state(fn () => [
            'dimension' => PreferenceDimension::Exercise,
            'exercise_id' => $exerciseId,
            'equipment_value' => null,
            'preference_key' => "exercise:{$exerciseId}",
            'original_text' => $originalText,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => PreferenceStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }
}
