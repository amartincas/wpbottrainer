<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\SafetyStatus;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
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
            // Hito 8.4: [] por defecto = "se preguntó, sin zona específica a
            // priorizar" — un perfil "completo" no requiere tener un foco.
            'primary_focus' => [],
            'secondary_focus' => null,
            'training_location' => fake()->randomElement(TrainingLocation::cases()),
            'available_equipment' => [],
            'equipment_fully_equipped' => false,
            'restrictions' => [],
            'sessions_per_week' => 3,
            // Hito 8.3: datos físicos deliberadamente null por defecto — son
            // contextuales y opcionales, "completo" no depende de tenerlos.
            'age' => null,
            'sex' => null,
            'weight_kg' => null,
            'height_cm' => null,
            'physical_stats_asked' => true,
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
            'primary_focus' => null,
            'training_location' => null,
            'available_equipment' => null,
            'restrictions' => null,
            'sessions_per_week' => null,
            'physical_stats_asked' => false,
        ]);
    }

    /**
     * Hito 8.4: fija un foco principal explícito para pruebas de scoring por
     * objetivo específico (p. ej. "quiero aumentar glúteos").
     */
    public function withPrimaryFocus(array $focuses): static
    {
        return $this->state(fn () => [
            'primary_focus' => array_map(fn (MuscleFocus $f) => $f->value, $focuses),
        ]);
    }
}
