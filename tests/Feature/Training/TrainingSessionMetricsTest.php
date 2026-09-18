<?php

use App\Models\Contact;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\TrainingPeriodResolver;
use App\Training\Support\TrainingSessionMetrics;
use Carbon\CarbonImmutable;

/**
 * Hito — Historial de progreso por período. Métrica REAL, independiente de
 * MAX_SESSIONS/WINDOW_WEEKS de TrainingHistoryContextProvider — un
 * COUNT() sin LIMIT por período. Pertenencia por completed_at, nunca
 * scheduled_at.
 */
function sessionMetrics(): TrainingSessionMetrics
{
    return new TrainingSessionMetrics;
}

function completedSession(Contact $contact, array $overrides = []): WorkoutSession
{
    return WorkoutSession::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'status' => WorkoutSessionStatus::Completed,
        'scheduled_at' => now(),
        'completed_at' => now(),
    ], $overrides));
}

it('counts 9 sessions completed within the last 4 weeks — never capped to 6 like TrainingHistoryContextProvider', function () {
    $contact = Contact::factory()->create();
    $now = CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC');

    // subDays($i+1): ninguna cae exactamente en $now (el fin exclusivo del
    // período last_4_weeks es $now mismo — coincidir con él excluiría esa
    // sesión, un detalle del test, no del criterio de negocio).
    for ($i = 0; $i < 9; $i++) {
        completedSession($contact, ['completed_at' => $now->subDays($i + 1)]);
    }

    $period = (new TrainingPeriodResolver)->resolve('last_4_weeks', 'America/Bogota', $now);

    expect(sessionMetrics()->completedCount($contact, $period))->toBe(9);
});

it('9 completed sessions within 4 weeks coexist correctly with TrainingHistoryContextProvider still capped to 6', function () {
    $contact = Contact::factory()->create();
    \App\Models\TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $now = CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC');

    for ($i = 0; $i < 9; $i++) {
        completedSession($contact, ['scheduled_at' => $now->subDays($i + 1), 'completed_at' => $now->subDays($i + 1)]);
    }

    $period = (new TrainingPeriodResolver)->resolve('last_4_weeks', 'America/Bogota', $now);
    $realMetric = sessionMetrics()->completedCount($contact, $period);

    $safetyResolver = new \App\Training\Support\SafetyRestrictionResolver(new \App\Training\Support\BodyRegionCanonicalMapper);
    $historyContext = (new \App\Training\Support\TrainingHistoryContextProvider($safetyResolver))->build($contact->fresh());

    expect($realMetric)->toBe(9);
    expect($historyContext->aggregates->sessionsCompletedInWindow)->toBeLessThanOrEqual(6);
    expect($historyContext->windowSessionsCount)->toBeLessThanOrEqual(6);
});

it('current_week counts only the 3 sessions completed this week, out of 9 within the last 4 weeks', function () {
    $contact = Contact::factory()->create();
    // 2026-09-16 es miércoles de la semana que empieza el 2026-09-14.
    $now = CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC');

    // 3 esta semana (lunes 14 en adelante). La tercera es $now menos un
    // minuto, nunca $now exacto — $now es también el fin EXCLUSIVO del
    // período last_4_weeks más abajo, y coincidir con él excluiría esa
    // sesión de esa métrica (detalle del test, no del criterio real).
    completedSession($contact, ['completed_at' => CarbonImmutable::parse('2026-09-14 10:00:00', 'UTC')]);
    completedSession($contact, ['completed_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC')]);
    completedSession($contact, ['completed_at' => $now->subMinute()]);

    // 6 más, anteriores a esta semana pero dentro de las últimas 4 semanas.
    for ($i = 1; $i <= 6; $i++) {
        completedSession($contact, ['completed_at' => CarbonImmutable::parse('2026-09-13 10:00:00', 'UTC')->subDays($i)]);
    }

    $resolver = new TrainingPeriodResolver;
    $currentWeek = $resolver->resolve('current_week', 'America/Bogota', $now);
    $last4Weeks = $resolver->resolve('last_4_weeks', 'America/Bogota', $now);

    expect(sessionMetrics()->completedCount($contact, $currentWeek))->toBe(3);
    expect(sessionMetrics()->completedCount($contact, $last4Weeks))->toBe(9);
});

it('sessions outside the requested period are never counted', function () {
    $contact = Contact::factory()->create();
    $now = CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC');

    completedSession($contact, ['completed_at' => $now->subWeeks(6)]); // fuera de last_4_weeks
    completedSession($contact, ['completed_at' => $now->subWeeks(2)]); // dentro de last_4_weeks

    $period = (new TrainingPeriodResolver)->resolve('last_4_weeks', 'America/Bogota', $now);

    expect(sessionMetrics()->completedCount($contact, $period))->toBe(1);
});

it('a Skipped session is never counted as completed, even inside the requested period', function () {
    $contact = Contact::factory()->create();
    $now = CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC');

    completedSession($contact, ['status' => WorkoutSessionStatus::Skipped, 'completed_at' => $now->subDay()]);
    completedSession($contact, ['completed_at' => $now->subDay()]);

    $period = (new TrainingPeriodResolver)->resolve('last_4_weeks', 'America/Bogota', $now);

    expect(sessionMetrics()->completedCount($contact, $period))->toBe(1);
});

it('a session scheduled on Sunday but completed on Monday belongs to the week it was completed, not the week it was scheduled', function () {
    $contact = Contact::factory()->create();
    // 2026-09-13 es domingo (semana que termina el 13); 2026-09-14 es
    // lunes (empieza la semana siguiente).
    completedSession($contact, [
        'scheduled_at' => CarbonImmutable::parse('2026-09-13 22:00:00', 'UTC'),
        'completed_at' => CarbonImmutable::parse('2026-09-14 10:00:00', 'UTC'),
    ]);

    $resolver = new TrainingPeriodResolver;

    // "esta semana" evaluada al mediodía del lunes 14 — la sesión SÍ cuenta.
    $weekOfMonday14 = $resolver->resolve('current_week', 'America/Bogota', CarbonImmutable::parse('2026-09-14 15:00:00', 'UTC'));
    expect(sessionMetrics()->completedCount($contact, $weekOfMonday14))->toBe(1);

    // "esta semana" evaluada al mediodía del domingo 13 (semana ANTERIOR,
    // antes de que exista la sesión) — nunca puede contarla: scheduled_at
    // no es lo que determina la pertenencia.
    $weekOfSunday13 = $resolver->resolve('current_week', 'America/Bogota', CarbonImmutable::parse('2026-09-13 15:00:00', 'UTC'));
    expect(sessionMetrics()->completedCount($contact, $weekOfSunday13))->toBe(0);
});

it('a session scheduled on Monday but completed the following week belongs to the week it was completed, not the week it was scheduled', function () {
    $contact = Contact::factory()->create();
    completedSession($contact, [
        'scheduled_at' => CarbonImmutable::parse('2026-09-14 09:00:00', 'UTC'), // lunes
        'completed_at' => CarbonImmutable::parse('2026-09-21 09:00:00', 'UTC'), // lunes SIGUIENTE
    ]);

    $resolver = new TrainingPeriodResolver;

    $weekOfScheduling = $resolver->resolve('current_week', 'America/Bogota', CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'));
    expect(sessionMetrics()->completedCount($contact, $weekOfScheduling))->toBe(0);

    $weekOfCompletion = $resolver->resolve('current_week', 'America/Bogota', CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    expect(sessionMetrics()->completedCount($contact, $weekOfCompletion))->toBe(1);
});

it('a session not yet completed (Scheduled, completed_at null) is never counted in any period', function () {
    $contact = Contact::factory()->create();
    WorkoutSession::factory()->create([
        'contact_id' => $contact->id,
        'status' => WorkoutSessionStatus::Scheduled,
        'scheduled_at' => now(),
        'completed_at' => null,
    ]);

    $resolver = new TrainingPeriodResolver;
    $now = CarbonImmutable::now();

    foreach (['current_week', 'last_4_weeks', 'all_time'] as $label) {
        $period = $resolver->resolve($label, 'America/Bogota', $now);
        expect(sessionMetrics()->completedCount($contact, $period))->toBe(0);
    }
});

it('completed_at exactly at the period start counts (inclusive); exactly at the period end does not (exclusive)', function () {
    $contact = Contact::factory()->create();
    $now = CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC');
    $period = (new TrainingPeriodResolver)->resolve('current_week', 'America/Bogota', $now);

    $atStart = completedSession($contact, ['completed_at' => $period->start]);

    expect(sessionMetrics()->completedCount($contact, $period))->toBe(1);

    $atStart->delete();
    completedSession($contact, ['completed_at' => $period->end]);

    expect(sessionMetrics()->completedCount($contact, $period))->toBe(0);
});

it('all_time counts sessions completed far in the past, with no lower bound', function () {
    $contact = Contact::factory()->create();
    completedSession($contact, ['completed_at' => CarbonImmutable::parse('2020-01-01 00:00:00', 'UTC')]);

    $period = (new TrainingPeriodResolver)->resolve('all_time', 'America/Bogota', CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));

    expect(sessionMetrics()->completedCount($contact, $period))->toBe(1);
});
