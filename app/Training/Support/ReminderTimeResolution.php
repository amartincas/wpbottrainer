<?php

namespace App\Training\Support;

use Carbon\CarbonInterface;

/**
 * Hito 10 — resultado determinista de `ReminderTimeResolver::resolve()`.
 * `fireAt` siempre en UTC. `recurrence` es `null` para un recordatorio
 * único; un array `{freq, day_of_week, time}` para uno recurrente — la
 * misma forma que se persiste tal cual en `Reminder.recurrence`.
 */
final readonly class ReminderTimeResolution
{
    public function __construct(
        public CarbonInterface $fireAt,
        public ?array $recurrence,
    ) {}
}
