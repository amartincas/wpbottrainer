<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Reminder;
use App\Training\Enums\ReminderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    protected $model = Reminder::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'tenant_id' => function (array $attributes) {
                return Contact::find($attributes['contact_id'])->tenant_id;
            },
            'type' => 'training_weekly',
            'status' => ReminderStatus::Pending,
            'fire_at' => now()->addWeek(),
            'recurrence' => ['freq' => 'weekly', 'day_of_week' => 2, 'time' => '19:00'],
            'awaiting_response_until' => null,
            'last_fired_at' => null,
            'created_from_suggestion_id' => null,
            'cancelled_at' => null,
            'recovery_attempts' => 0,
        ];
    }

    public function oneOff(): static
    {
        return $this->state(fn () => ['type' => 'training_one_off', 'recurrence' => null]);
    }

    public function sending(): static
    {
        return $this->state(fn () => ['status' => ReminderStatus::Sending]);
    }

    public function stuckSending(int $minutesAgo = 15): static
    {
        return $this->state(fn () => [
            'status' => ReminderStatus::Sending,
            'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => ReminderStatus::Cancelled, 'cancelled_at' => now()]);
    }

    public function awaitingResponse(): static
    {
        return $this->state(fn () => [
            'status' => ReminderStatus::Sent,
            'last_fired_at' => now(),
            'awaiting_response_until' => now()->addHours(2),
        ]);
    }
}
