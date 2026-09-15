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

// ── H16.2 Fase 1.3 (auditoría de flujo conversacional, Caso 2) ──────────
// Hora sin día explícito ("recuérdame a las 7"): se infiere HOY si la hora
// todavía no pasó, o MAÑANA si ya pasó — nunca delegado a la IA, nunca
// ambiguo por defecto.

it('a bare time with no day, still in the future today, resolves to TODAY', function () {
    // Miércoles 2026-09-09, son las 10:00 — pide "a las 19:00".
    $now = CarbonImmutable::parse('2026-09-09 10:00:00', 'America/Bogota');

    $result = reminderTimeResolver()->resolve(null, '19:00', false, 'America/Bogota', $now);

    expect($result)->not->toBeNull();
    expect($result->recurrence)->toBeNull();
    expect($result->fireAt->setTimezone('America/Bogota')->toDateTimeString())->toBe('2026-09-09 19:00:00');
});

it('a bare time with no day, only slightly before the requested hour today, still resolves to TODAY', function () {
    $now = CarbonImmutable::parse('2026-09-09 18:30:00', 'America/Bogota');

    $result = reminderTimeResolver()->resolve(null, '19:00', false, 'America/Bogota', $now);

    expect($result->fireAt->setTimezone('America/Bogota')->toDateTimeString())->toBe('2026-09-09 19:00:00');
});

it('a bare time with no day, already passed today, resolves to TOMORROW', function () {
    $now = CarbonImmutable::parse('2026-09-09 20:00:00', 'America/Bogota');

    $result = reminderTimeResolver()->resolve(null, '19:00', false, 'America/Bogota', $now);

    expect($result->fireAt->setTimezone('America/Bogota')->toDateTimeString())->toBe('2026-09-10 19:00:00');
});

it('a bare time with no day, EXACTLY equal to the current time, is treated as already passed and resolves to TOMORROW', function () {
    $now = CarbonImmutable::parse('2026-09-09 19:00:00', 'America/Bogota');

    $result = reminderTimeResolver()->resolve(null, '19:00', false, 'America/Bogota', $now);

    expect($result->fireAt->setTimezone('America/Bogota')->toDateTimeString())->toBe('2026-09-10 19:00:00');
});

it('the implicit today/tomorrow inference respects the tenant timezone, not the server/UTC clock', function () {
    // 2026-09-09 23:30 UTC == 2026-09-09 18:30 en America/Bogota (UTC-5, sin
    // horario de verano) == 2026-09-10 01:30 en Europe/Madrid (UTC+2 en
    // septiembre) — la misma marca de tiempo produce un resultado distinto
    // en cada timezone.
    $now = CarbonImmutable::parse('2026-09-09 23:30:00', 'UTC');

    $bogota = reminderTimeResolver()->resolve(null, '19:00', false, 'America/Bogota', $now); // local: 2026-09-09 18:30 -> hoy (09) 19:00
    $madrid = reminderTimeResolver()->resolve(null, '19:00', false, 'Europe/Madrid', $now); // local: 2026-09-10 01:30 -> hoy (10) 19:00

    expect($bogota->fireAt->setTimezone('America/Bogota')->toDateTimeString())->toBe('2026-09-09 19:00:00');
    expect($madrid->fireAt->setTimezone('Europe/Madrid')->toDateTimeString())->toBe('2026-09-10 19:00:00');
});

it('a RECURRING reminder with no day is still rejected even though a bare time now resolves for a one-off', function () {
    // No-regresión explícita: inferir "hoy"/"mañana" para una ocurrencia
    // única nunca debe abrir la puerta a "todos los días" para una
    // recurrente sin día real.
    $now = CarbonImmutable::parse('2026-09-09 10:00:00', 'America/Bogota');

    expect(reminderTimeResolver()->resolve(null, '19:00', true, 'America/Bogota', $now))->toBeNull();
});
