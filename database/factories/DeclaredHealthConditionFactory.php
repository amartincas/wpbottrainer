<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Training\Enums\HealthConditionCategory;
use App\Training\Enums\HealthConditionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeclaredHealthCondition>
 */
class DeclaredHealthConditionFactory extends Factory
{
    protected $model = DeclaredHealthCondition::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'original_text' => 'tengo una lesión en el hombro',
            'functional_limitation_text' => null,
            'source_message_id' => null,
            'category' => HealthConditionCategory::PossibleInjury,
            'suggested_body_region' => null,
            'status' => HealthConditionStatus::PendingReview,
            'related_restriction_id' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'declared_at' => now(),
        ];
    }

    public function possibleRecovery(): static
    {
        return $this->state(fn () => [
            'category' => HealthConditionCategory::PossibleRecovery,
            'original_text' => 'ya estoy recuperado',
        ]);
    }

    public function professionalIndication(): static
    {
        return $this->state(fn () => [
            'category' => HealthConditionCategory::ProfessionalIndication,
            'original_text' => 'el fisioterapeuta me dijo que evite el hombro',
        ]);
    }

    public function resolvedNoRestriction(): static
    {
        return $this->state(fn () => ['status' => HealthConditionStatus::ResolvedNoRestriction]);
    }

    public function superseded(): static
    {
        return $this->state(fn () => ['status' => HealthConditionStatus::Superseded]);
    }
}
