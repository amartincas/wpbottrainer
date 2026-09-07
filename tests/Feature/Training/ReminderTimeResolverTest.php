<?php

use App\Training\Support\ReminderTimeResolver;
use Carbon\CarbonImmutable;

function reminderTimeResolver(): ReminderTimeResolver
{
    return new ReminderTimeResolver;
}

it('resolves "tomorrow at 07:00" as a single reminder in Bogota time', function () {
    // Miércoles 2026-09-09 10:00 America/Bogota.
    $now = CarbonImmutable::parse('2026-09-09 10:00:00', 'America/Bogota');

    $result = reminderTimeResolver()->resolve('tomorrow', '07:00', false, 'America/Bogota', $now);

    expect($result)->not->toBeNull();
    expect($result->recurrence)->toBeNull();
    // 2026-09-10 07:00 America/Bogota (UTC-5) == 2026-09-10 12:00 UTC.
    expect($result->fireAt->toDateTimeString())->toBe('2026-09-10 12:00:00');
});

it('resolves "every tuesday at 19:00" as a recurring reminder', function () {
    // Miércoles 2026-09-09.
    $now = CarbonImmutable::parse('2026-09-09 10:00:00', 'America/Bogota');

    $result = reminderTimeResolver()->resolve('tuesday', '19:00', true, 'America/Bogota', $now);

    expect($result->recurrence)->toBe(['freq' => 'weekly', 'day_of_week' => 2, 'time' => '19:00']);
    // El próximo martes tras un miércoles es 6 días después: 2026-09-15.
    expect($result->fireAt->setTimezone('America/Bogota')->toDateString())->toBe('2026-09-15');
});

it('a specific weekday whose time already passed today rolls to next week, never to the past', function () {
    // Es martes 2026-09-08, ya son las 20:00 — "el martes a las 7pm" ya pasó hoy.
    $now = CarbonImmutable::parse('2026-09-08 20:00:00', 'America/Bogota');

    $result = reminderTimeResolver()->resolve('tuesday', '19:00', false, 'America/Bogota', $now);

    expect($result->fireAt->isFuture())->toBeTrue();
    expect($result->fireAt->setTimezone('America/Bogota')->toDateString())->toBe('2026-09-15');
});

it('"today" with an already-passed time is ambiguous and returns null (never silently corrected)', function () {
    $now = CarbonImmutable::parse('2026-09-09 20:00:00', 'America/Bogota');

    $result = reminderTimeResolver()->resolve('today', '07:00', false, 'America/Bogota', $now);

    expect($result)->toBeNull();
});

it('returns null when no time is given at all — never guesses a default hour', function () {
    $now = CarbonImmutable::now();

    expect(reminderTimeResolver()->resolve('tomorrow', null, false, 'America/Bogota', $now))->toBeNull();
});

it('returns null for an invalid time override (never silently corrected)', function () {
    $now = CarbonImmutable::now();

    expect(reminderTimeResolver()->resolve('tomorrow', '25:99', false, 'America/Bogota', $now))->toBeNull();
    expect(reminderTimeResolver()->resolve('monday', '8pm', true, 'America/Bogota', $now))->toBeNull();
});

it('a recurring reminder requires a real weekday — "every today"/"every tomorrow" is rejected', function () {
    $now = CarbonImmutable::now();

    expect(reminderTimeResolver()->resolve('today', '07:00', true, 'America/Bogota', $now))->toBeNull();
    expect(reminderTimeResolver()->resolve(null, '07:00', true, 'America/Bogota', $now))->toBeNull();
});

it('the same local day/time resolves to a different UTC instant for a different timezone', function () {
    $now = CarbonImmutable::parse('2026-09-09 10:00:00', 'UTC');

    $bogota = reminderTimeResolver()->resolve('tomorrow', '19:00', false, 'America/Bogota', $now);
    $madrid = reminderTimeResolver()->resolve('tomorrow', '19:00', false, 'Europe/Madrid', $now);

    expect($bogota->fireAt->toDateTimeString())->not->toBe($madrid->fireAt->toDateTimeString());
});
