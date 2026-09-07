<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ReminderSuggestion;
use App\Training\Enums\ReminderSuggestionOrigin;
use App\Training\Enums\ReminderSuggestionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderSuggestion>
 */
class ReminderSuggestionFactory extends Factory
{
    protected $model = ReminderSuggestion::class;

    public function definition(): array
    {
        return [
            // contact_id se resuelve primero; tenant_id lo deriva de ESE
            // mismo contacto (no de un Tenant::factory() independiente) para
            // que nunca queden desalineados cuando un test no sobreescribe
            // ninguno de los dos explícitamente.
            'contact_id' => Contact::factory(),
            'tenant_id' => function (array $attributes) {
                return Contact::find($attributes['contact_id'])->tenant_id;
            },
            'origin' => ReminderSuggestionOrigin::UserRequest,
            'trigger_reason' => null,
            'proposed_type' => 'training_weekly',
            'proposed_params' => ['day_of_week' => 2, 'time' => '19:00'],
            'status' => ReminderSuggestionStatus::Pending,
            'expires_at' => now()->addHours(24),
        ];
    }

    public function proactive(string $reason): static
    {
        return $this->state(fn () => [
            'origin' => ReminderSuggestionOrigin::Proactive,
            'trigger_reason' => $reason,
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn () => ['status' => ReminderSuggestionStatus::Declined]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => ReminderSuggestionStatus::Pending,
            'expires_at' => now()->subHour(),
        ]);
    }
}
