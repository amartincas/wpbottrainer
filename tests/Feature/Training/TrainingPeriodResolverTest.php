<?php

use App\Training\Support\TrainingPeriodResolver;
use Carbon\CarbonImmutable;

/**
 * Hito — Historial de progreso por período. `TrainingPeriodResolver` es la
 * única autoridad que calcula fechas reales — nunca el LLM. Todos los
 * límites deben calcularse en la timezone del Tenant, nunca en UTC crudo
 * (ver docblock de la clase).
 */
function periodResolver(): TrainingPeriodResolver
{
    return new TrainingPeriodResolver;
}

it('current_week: mid-week instant resolves to Monday 00:00 local through next Monday 00:00 local, in UTC', function () {
    // 2026-09-16 15:00 UTC = miércoles 10:00 en America/Bogota (UTC-5) —
    // mismo día calendario en ambas zonas, caso simple sin cruce de límite.
    $now = CarbonImmutable::parse('2026-09-16 15:00:00', 'UTC');

    $period = periodResolver()->resolve('current_week', 'America/Bogota', $now);

    expect($period->label)->toBe('current_week');
    // Lunes 2026-09-14 00:00 Bogotá = 2026-09-14 05:00 UTC.
    expect($period->start->toIso8601String())->toBe('2026-09-14T05:00:00+00:00');
    // Lunes 2026-09-21 00:00 Bogotá = 2026-09-21 05:00 UTC (exclusivo).
    expect($period->end->toIso8601String())->toBe('2026-09-21T05:00:00+00:00');
});

it('current_week: converts to the Tenant timezone BEFORE deciding the week — UTC already Monday but Bogotá still Sunday of the previous week', function () {
    // 2026-09-14 03:00 UTC es lunes en UTC, pero en America/Bogota
    // (UTC-5) son las 2026-09-13 22:00 — domingo de la semana ANTERIOR.
    // Si el cálculo usara el calendario UTC directamente (bug), "esta
    // semana" empezaría el 2026-09-14; el criterio correcto (conversión a
    // hora local ANTES de decidir la semana) debe seguir en la semana que
    // empezó el 2026-09-07.
    $now = CarbonImmutable::parse('2026-09-14 03:00:00', 'UTC');

    $period = periodResolver()->resolve('current_week', 'America/Bogota', $now);

    // Lunes 2026-09-07 00:00 Bogotá = 2026-09-07 05:00 UTC — NO 2026-09-14.
    expect($period->start->toIso8601String())->toBe('2026-09-07T05:00:00+00:00');
    expect($period->end->toIso8601String())->toBe('2026-09-14T05:00:00+00:00');
});

it('current_week: near end-of-Sunday UTC without crossing into Monday locally stays in the same (previous) week', function () {
    // 2026-09-20 23:59 UTC = 2026-09-20 18:59 en Bogotá — sigue siendo
    // domingo en ambas zonas (sin cruce), confirma que el caso anterior es
    // específicamente por el cruce de calendario, no un desfase genérico.
    $now = CarbonImmutable::parse('2026-09-20 23:59:00', 'UTC');

    $period = periodResolver()->resolve('current_week', 'America/Bogota', $now);

    expect($period->start->toIso8601String())->toBe('2026-09-14T05:00:00+00:00');
    expect($period->end->toIso8601String())->toBe('2026-09-21T05:00:00+00:00');
});

it('current_week: a different Tenant timezone produces different real boundaries for the exact same instant', function () {
    $now = CarbonImmutable::parse('2026-09-14 03:00:00', 'UTC');

    $bogota = periodResolver()->resolve('current_week', 'America/Bogota', $now);
    $utc = periodResolver()->resolve('current_week', 'UTC', $now);

    // En Bogotá (UTC-5) ese instante sigue en la semana del 07; en UTC puro
    // ya es lunes 14 — el mismo instante produce límites reales distintos.
    expect($bogota->start->toIso8601String())->toBe('2026-09-07T05:00:00+00:00');
    expect($utc->start->toIso8601String())->toBe('2026-09-14T00:00:00+00:00');
});

it('last_4_weeks: start is exactly 4 weeks before now (in the Tenant timezone), end is now, both in UTC', function () {
    $now = CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC');

    $period = periodResolver()->resolve('last_4_weeks', 'America/Bogota', $now);

    expect($period->label)->toBe('last_4_weeks');
    expect($period->start->toIso8601String())->toBe('2026-08-20T12:00:00+00:00');
    expect($period->end->toIso8601String())->toBe('2026-09-17T12:00:00+00:00');
});

it('all_time: has no lower bound and end is now', function () {
    $now = CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC');

    $period = periodResolver()->resolve('all_time', 'America/Bogota', $now);

    expect($period->label)->toBe('all_time');
    expect($period->start)->toBeNull();
    expect($period->end->toIso8601String())->toBe('2026-09-17T12:00:00+00:00');
});

it('an unknown or null label falls back to last_4_weeks, never an error or an empty period', function () {
    $now = CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC');

    $unknown = periodResolver()->resolve('bogus_label', 'America/Bogota', $now);
    $null = periodResolver()->resolve(null, 'America/Bogota', $now);

    expect($unknown->label)->toBe('last_4_weeks');
    expect($null->label)->toBe('last_4_weeks');
    expect($unknown->start->toIso8601String())->toBe($null->start->toIso8601String());
});
