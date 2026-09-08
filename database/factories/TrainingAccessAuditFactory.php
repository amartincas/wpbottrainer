<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\TrainingAccess;
use App\Models\TrainingAccessAudit;
use App\Models\User;
use App\Training\Enums\TrainingAccessAuditAction;
use App\Training\Enums\TrainingAccessStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingAccessAudit>
 */
class TrainingAccessAuditFactory extends Factory
{
    protected $model = TrainingAccessAudit::class;

    public function definition(): array
    {
        return [
            // contact_id/training_access_id se derivan juntos por defecto,
            // mismo patrón de closures dependientes ya usado en
            // ReminderFactory/PaymentFactory (Hito 10/11).
            'training_access_id' => TrainingAccess::factory(),
            'contact_id' => function (array $attributes) {
                return TrainingAccess::find($attributes['training_access_id'])->contact_id;
            },
            'action' => TrainingAccessAuditAction::TrialGranted,
            'performed_by' => User::factory(),
            'previous_status' => null,
            'new_status' => TrainingAccessStatus::Trial,
            'previous_expires_at' => null,
            'new_expires_at' => now()->addDays(7),
            'reason' => null,
            'created_at' => now(),
        ];
    }
}
