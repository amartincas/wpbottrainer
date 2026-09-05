<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\TrainingRestriction;
use App\Training\Enums\BodyRegion;
use App\Training\Enums\RestrictionSource;
use App\Training\Enums\RestrictionStatus;
use App\Training\Enums\RestrictionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingRestriction>
 */
class TrainingRestrictionFactory extends Factory
{
    protected $model = TrainingRestriction::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'body_region' => BodyRegion::Shoulder,
            'restriction_type' => RestrictionType::ExcludeExercise,
            'source' => RestrictionSource::UserExplicit,
            'status' => RestrictionStatus::Confirmed,
            'original_text' => 'no puedo levantar el brazo por encima de la cabeza',
            'declared_health_condition_id' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => [
            'status' => RestrictionStatus::PendingReview,
            'source' => RestrictionSource::UserVague,
            'original_text' => 'tengo una lesión en el hombro',
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => RestrictionStatus::Resolved]);
    }

    public function superseded(): static
    {
        return $this->state(fn () => ['status' => RestrictionStatus::Superseded]);
    }

    public function forBodyRegion(BodyRegion $region): static
    {
        return $this->state(fn () => ['body_region' => $region]);
    }
}
