<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\SafetyStatus;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingProfile>
 */
class TrainingProfileFactory extends Factory
{
    protected $model = TrainingProfile::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'goal' => fake()->randomElement(TrainingGoal::cases()),
            'experience_level' => fake()->randomElement(ExperienceLevel::cases()),
            'available_equipment' => [],
            'restrictions' => [],
            'sessions_per_week' => 3,
            'split_type' => SplitType::FullBody,
            'next_focus' => null,
            'safety_status' => SafetyStatus::Normal,
            'safety_flag_reason' => null,
            'safety_flagged_at' => null,
        ];
    }

    public function flaggedForSafetyReview(string $reason = 'test-reason'): static
    {
        return $this->state(fn () => [
            'safety_status' => SafetyStatus::FlaggedForReview,
            'safety_flag_reason' => $reason,
            'safety_flagged_at' => now(),
        ]);
    }

    /**
     * A freshly-started onboarding: only the system defaults are set
     * (split_type, safety_status), everything the user must answer is still
     * null. Mirrors exactly what TrainingHandler creates on first contact.
     */
    public function incomplete(): static
    {
        return $this->state(fn () => [
            'goal' => null,
            'experience_level' => null,
            'available_equipment' => null,
            'restrictions' => null,
            'sessions_per_week' => null,
        ]);
    }
}
