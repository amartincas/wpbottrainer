<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\ExerciseSet;
use App\Models\TrainingProfile;
use App\Models\TrainingRestriction;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\BodyRegion;
use App\Training\Enums\HistoryExerciseOutcome;
use App\Training\Enums\RestrictionStatus;
use App\Training\Enums\TrackingType;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingHistoryContextProvider;
use Illuminate\Support\Facades\DB;

/**
 * Bloque 6 (D049) — TrainingHistoryContextProvider: capa de solo lectura,
 * determinista, sin IA. Ver docs/DECISIONS.md.
 */
function historyProvider(): TrainingHistoryContextProvider
{
    return new TrainingHistoryContextProvider(new SafetyRestrictionResolver(new BodyRegionCanonicalMapper));
}

/**
 * Crea un WorkoutExercise "realizado" con las series indicadas.
 *
 * @param  array<int, array{reps?: ?int, load?: ?float, duration?: ?int}>  $sets
 */
function performedExercise(WorkoutSession $session, Exercise $exercise, array $sets, ?int $rpe = null, ?string $note = null, ?\Carbon\CarbonInterface $loggedAt = null): WorkoutExercise
{
    $we = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
    ]);

    $log = ExerciseLog::factory()->create([
        'workout_exercise_id' => $we->id,
        'rpe' => $rpe,
        'note' => $note,
        'logged_at' => $loggedAt ?? $session->scheduled_at,
    ]);

    foreach ($sets as $index => $set) {
        ExerciseSet::factory()->create([
            'exercise_log_id' => $log->id,
            'set_number' => $index + 1,
            'actual_reps' => $set['reps'] ?? null,
            'actual_load' => $set['load'] ?? null,
            'actual_duration_seconds' => $set['duration'] ?? null,
        ]);
    }

    return $we->fresh(['exerciseLog.exerciseSets']);
}

function skippedExercise(WorkoutSession $session, Exercise $exercise, ?string $skipReason = null): WorkoutExercise
{
    $we = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
    ]);

    ExerciseLog::factory()->create([
        'workout_exercise_id' => $we->id,
        'skip_reason' => $skipReason,
        'note' => 'No realizado.',
    ]);

    return $we->fresh(['exerciseLog.exerciseSets']);
}

function unreportedExercise(WorkoutSession $session, Exercise $exercise): WorkoutExercise
{
    return WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
    ]);
}

// ── 1: sin historial ──

it('1: a contact with no history gets an empty, well-formed context', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $context = historyProvider()->build($contact);

    expect($context->windowSessionsCount)->toBe(0);
    expect($context->windowWeeks)->toBe(4);
    expect($context->sessions)->toBe([]);
    expect($context->aggregates->sessionsCompletedInWindow)->toBe(0);
    expect($context->aggregates->lastLoadByExerciseId)->toBe([]);
    expect($context->aggregates->daysSinceLastCompletedSession)->toBeNull();
});

// ── 2/3: una sesión / varias sesiones ──

it('2: a contact with a single completed session gets one session entry with its exercises', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create(['name' => 'Sentadilla']);
    performedExercise($session, $exercise, [['reps' => 10, 'load' => 40]]);

    $context = historyProvider()->build($contact);

    expect($context->windowSessionsCount)->toBe(1);
    expect($context->sessions[0]->workoutSessionId)->toBe($session->id);
    expect($context->sessions[0]->exercises)->toHaveCount(1);
    expect($context->sessions[0]->exercises[0]->name)->toBe('Sentadilla');
});

it('3: a contact with several sessions gets them all within the window', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    for ($i = 0; $i < 3; $i++) {
        WorkoutSession::factory()->completed()->create([
            'contact_id' => $contact->id,
            'scheduled_at' => now()->subDays($i),
        ]);
    }

    $context = historyProvider()->build($contact);

    expect($context->windowSessionsCount)->toBe(3);
});

// ── 4/5/6: ventana (6 sesiones AND 4 semanas) ──

it('4: only the 6 most recent sessions are included, even if more exist within 4 weeks', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    for ($i = 0; $i < 8; $i++) {
        WorkoutSession::factory()->completed()->create([
            'contact_id' => $contact->id,
            'scheduled_at' => now()->subDays($i),
        ]);
    }

    $context = historyProvider()->build($contact);

    expect($context->windowSessionsCount)->toBe(6);
    // Las incluidas deben ser las 6 más recientes (días 0-5), nunca las más antiguas.
    $days = collect($context->sessions)->map(fn ($s) => $s->scheduledAt->diffInDays(now()))->sort()->values();
    expect($days->max())->toBeLessThan(6);
});

it('5: a session older than 4 weeks is excluded even if fewer than 6 sessions exist', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subWeeks(5)]);
    WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);

    $context = historyProvider()->build($contact);

    expect($context->windowSessionsCount)->toBe(1);
});

it('6: AND between both bounds — a session within the 6-count but outside 4 weeks is still excluded', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    // Solo 2 sesiones en total (muy por debajo del límite de 6), pero una
    // está fuera de la ventana de 4 semanas.
    WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subWeeks(6)]);
    $recent = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(1)]);

    $context = historyProvider()->build($contact);

    expect($context->windowSessionsCount)->toBe(1);
    expect($context->sessions[0]->workoutSessionId)->toBe($recent->id);
});

// ── 7/8/9: estados de sesión ──

it('7: the active scheduled session is excluded from the history window', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);

    $context = historyProvider()->build($contact);

    expect($context->windowSessionsCount)->toBe(0);
});

it('8: a completed session is included', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);

    $context = historyProvider()->build($contact);

    expect($context->windowSessionsCount)->toBe(1);
    expect($context->sessions[0]->status)->toBe(WorkoutSessionStatus::Completed);
});

it('9: a skipped session is included when it genuinely exists', function () {
    // WorkoutSessionStatus::Skipped no lo escribe ningún flujo real hoy —
    // este test demuestra que el proveedor lo SOPORTA si existiera, sin
    // fabricarlo por su cuenta.
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->skipped()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);

    $context = historyProvider()->build($contact);

    expect($context->windowSessionsCount)->toBe(1);
    expect($context->sessions[0]->status)->toBe(WorkoutSessionStatus::Skipped);
    // Nunca cuenta como "completed".
    expect($context->aggregates->sessionsCompletedInWindow)->toBe(0);
});

// ── 10/11/12: estados de ejercicio ──

it('10/11/12: exercise outcome is derived exactly from ExerciseLog/ExerciseSet presence', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);

    $unreported = unreportedExercise($session, Exercise::factory()->create(['name' => 'Unreported']));
    $performed = performedExercise($session, Exercise::factory()->create(['name' => 'Performed']), [['reps' => 10, 'load' => 20]]);
    $skipped = skippedExercise($session, Exercise::factory()->create(['name' => 'Skipped']), 'cant_do');

    $context = historyProvider()->build($contact);
    $byName = collect($context->sessions[0]->exercises)->keyBy('name');

    expect($byName['Unreported']->outcome)->toBe(HistoryExerciseOutcome::Unreported);
    expect($byName['Performed']->outcome)->toBe(HistoryExerciseOutcome::Performed);
    expect($byName['Skipped']->outcome)->toBe(HistoryExerciseOutcome::Skipped);
    expect($byName['Skipped']->skipReason->value)->toBe('cant_do');
});

// ── 13-19: detalle de la serie/ejercicio ──

it('13/14/15: multiple sets preserve their individual reps and load', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    performedExercise($session, $exercise, [
        ['reps' => 10, 'load' => 30],
        ['reps' => 10, 'load' => 35],
        ['reps' => 8, 'load' => 40],
    ]);

    $context = historyProvider()->build($contact);
    $sets = $context->sessions[0]->exercises[0]->sets;

    expect($sets)->toHaveCount(3);
    expect($sets[0]->reps)->toBe(10);
    expect($sets[2]->load)->toBe(40.0);
});

it('16: RPE is preserved from the ExerciseLog', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    performedExercise($session, Exercise::factory()->create(), [['reps' => 10, 'load' => 20]], rpe: 8);

    $context = historyProvider()->build($contact);

    expect($context->sessions[0]->exercises[0]->rpe)->toBe(8);
});

it('17: duration-based sets preserve durationSeconds', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->timeBased()->create();

    performedExercise($session, $exercise, [['duration' => 45]]);

    $context = historyProvider()->build($contact);

    expect($context->sessions[0]->exercises[0]->sets[0]->durationSeconds)->toBe(45);
    expect($context->sessions[0]->exercises[0]->sets[0]->load)->toBeNull();
});

it('18: notes are preserved verbatim', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    performedExercise($session, Exercise::factory()->create(), [['reps' => 10, 'load' => 20]], note: 'Me costó la última serie');

    $context = historyProvider()->build($contact);

    expect($context->sessions[0]->exercises[0]->note)->toBe('Me costó la última serie');
});

it('19: skip_reason is preserved when the exercise was skipped', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    skippedExercise($session, Exercise::factory()->create(), 'no_time');

    $context = historyProvider()->build($contact);

    expect($context->sessions[0]->exercises[0]->skipReason->value)->toBe('no_time');
});

// ── 20-23: snapshots y catálogo actual ──

it('20: the exercise name always comes from exercise_snapshot, never the live Exercise', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create(['name' => 'Nombre original']);
    $we = performedExercise($session, $exercise, [['reps' => 10, 'load' => 20]]);

    // El catálogo cambia DESPUÉS — el snapshot ya congelado no debe verse afectado.
    $exercise->update(['name' => 'Nombre cambiado']);

    $context = historyProvider()->build($contact->fresh());

    expect($context->sessions[0]->exercises[0]->name)->toBe('Nombre original');
});

it('21: a session created before Block 3 (no prescription_context_snapshot) leaves decidedFocus/goal null, never invented', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'goal' => \App\Training\Enums\TrainingGoal::BuildMuscle]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'prescription_context_snapshot' => null]);
    performedExercise($session, Exercise::factory()->create(), [['reps' => 10, 'load' => 20]]);

    $context = historyProvider()->build($contact);

    expect($context->sessions[0]->decidedFocus)->toBeNull();
    expect($context->sessions[0]->goal)->toBeNull();
});

it('22: an exercise that is currently inactive still appears in history via its snapshot', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create(['name' => 'Ejercicio inactivo']);
    performedExercise($session, $exercise, [['reps' => 10, 'load' => 20]]);

    $exercise->update(['is_active' => false]);

    $context = historyProvider()->build($contact->fresh());

    expect($context->sessions[0]->exercises[0]->name)->toBe('Ejercicio inactivo');
});

it('23: a deleted exercise (exercise_id becomes null) still appears in session detail but is excluded from grouped aggregates', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create(['name' => 'Ejercicio borrado']);
    performedExercise($session, $exercise, [['reps' => 10, 'load' => 20]]);

    $exercise->delete(); // nullOnDelete

    $context = historyProvider()->build($contact->fresh());

    expect($context->sessions[0]->exercises[0]->exerciseId)->toBeNull();
    expect($context->sessions[0]->exercises[0]->name)->toBe('Ejercicio borrado');
    expect($context->aggregates->lastLoadByExerciseId)->toBe([]);
    expect($context->aggregates->exercisesRepeatedInWindow)->toBe([]);
});

// ── 24-30: agregados de carga ──

it('24/25: lastLoad and bestRecentLoad are computed correctly across executions', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    $older = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(3)]);
    performedExercise($older, $exercise, [['reps' => 10, 'load' => 50]], loggedAt: now()->subDays(3));

    $newer = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    performedExercise($newer, $exercise, [['reps' => 10, 'load' => 30]], loggedAt: now()->subDay());

    $context = historyProvider()->build($contact->fresh());

    expect($context->aggregates->lastLoadByExerciseId[$exercise->id])->toBe(30.0); // la más reciente
    expect($context->aggregates->bestRecentLoadByExerciseId[$exercise->id])->toBe(50.0); // la mejor de la ventana
});

it('26: actual_load = null is ignored, never treated as 0', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    performedExercise($session, $exercise, [['reps' => 10, 'load' => null], ['reps' => 10, 'load' => 25]]);

    $context = historyProvider()->build($contact->fresh());

    expect($context->aggregates->lastLoadByExerciseId[$exercise->id])->toBe(25.0);
});

it('27: actual_load = 0 is preserved as a real value, distinct from "no data"', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    performedExercise($session, $exercise, [['reps' => 12, 'load' => 0]]);

    $context = historyProvider()->build($contact->fresh());

    expect($context->aggregates->lastLoadByExerciseId)->toHaveKey($exercise->id);
    expect($context->aggregates->lastLoadByExerciseId[$exercise->id])->toBe(0.0);
});

it('28: a duration-based exercise never appears in the load maps', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->timeBased()->create();

    performedExercise($session, $exercise, [['duration' => 30]]);

    $context = historyProvider()->build($contact->fresh());

    expect($context->aggregates->lastLoadByExerciseId)->not->toHaveKey($exercise->id);
    expect($context->aggregates->bestRecentLoadByExerciseId)->not->toHaveKey($exercise->id);
});

it('29: different loads across sets of the same execution use the maximum', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    performedExercise($session, $exercise, [
        ['reps' => 10, 'load' => 30],
        ['reps' => 10, 'load' => 35],
        ['reps' => 8, 'load' => 40],
    ]);

    $context = historyProvider()->build($contact->fresh());

    expect($context->aggregates->lastLoadByExerciseId[$exercise->id])->toBe(40.0);
});

it('30: when the most recent execution has no load data, lastLoad does NOT search further back', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    $older = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(3)]);
    performedExercise($older, $exercise, [['reps' => 10, 'load' => 50]], loggedAt: now()->subDays(3));

    $newer = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    performedExercise($newer, $exercise, [['reps' => 10, 'load' => null]], loggedAt: now()->subDay()); // sin carga

    $context = historyProvider()->build($contact->fresh());

    expect($context->aggregates->lastLoadByExerciseId)->not->toHaveKey($exercise->id);
    // bestRecentLoad SÍ sigue viendo la ejecución anterior dentro de la ventana.
    expect($context->aggregates->bestRecentLoadByExerciseId[$exercise->id])->toBe(50.0);
});

// ── 31-34: otros agregados ──

it('31: recentRepRange reports the min/max reps seen for an exercise', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    $s1 = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
    performedExercise($s1, $exercise, [['reps' => 8, 'load' => 20]], loggedAt: now()->subDays(2));
    $s2 = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    performedExercise($s2, $exercise, [['reps' => 12, 'load' => 20]], loggedAt: now()->subDay());

    $context = historyProvider()->build($contact->fresh());

    expect($context->aggregates->recentRepRange[$exercise->id])->toBe(['min' => 8, 'max' => 12]);
});

it('32: lastPerformedAt reflects only performed executions, using their logged_at', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();
    // startOfSecond(): la columna `logged_at` no conserva microsegundos, así
    // que se compara sin ellos para evitar un falso negativo por precisión.
    $loggedAt = now()->subHours(5)->startOfSecond();

    performedExercise($session, $exercise, [['reps' => 10, 'load' => 20]], loggedAt: $loggedAt);

    $context = historyProvider()->build($contact->fresh());

    expect($context->aggregates->lastPerformedAtByExerciseId[$exercise->id]->eq($loggedAt))->toBeTrue();
});

it('33: exercisesRepeatedInWindow counts only performed occurrences', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    $s1 = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
    performedExercise($s1, $exercise, [['reps' => 10, 'load' => 20]], loggedAt: now()->subDays(2));
    $s2 = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    skippedExercise($s2, $exercise); // no cuenta
    $s3 = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()]);
    performedExercise($s3, $exercise, [['reps' => 10, 'load' => 20]], loggedAt: now());

    $context = historyProvider()->build($contact->fresh());

    expect($context->aggregates->exercisesRepeatedInWindow[$exercise->id])->toBe(2);
});

it('34: daysSinceLastCompletedSession measures from the most recent completed session\'s effective date', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->completed()->create([
        'contact_id' => $contact->id,
        'scheduled_at' => now()->subDays(10),
        'completed_at' => now()->subDays(3),
    ]);

    $context = historyProvider()->build($contact->fresh());

    expect($context->aggregates->daysSinceLastCompletedSession)->toBe(3);
});

// ── 35: sin porcentaje de adherencia ──

it('35: HistoryAggregates never exposes an adherence percentage field', function () {
    $reflection = new ReflectionClass(\App\Training\Support\HistoryAggregates::class);
    $propertyNames = array_map(fn ($p) => $p->getName(), $reflection->getProperties());

    foreach ($propertyNames as $name) {
        expect(mb_strtolower($name))->not->toContain('adherence');
        expect(mb_strtolower($name))->not->toContain('percent');
    }
});

// ── 36: seguridad ──

it('36: activeSafetyBodyRegions reflects an existing confirmed TrainingRestriction via SafetyRestrictionResolver, never recalculated', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingRestriction::factory()->create([
        'contact_id' => $contact->id,
        'body_region' => BodyRegion::Knee,
        'status' => RestrictionStatus::Confirmed,
    ]);

    $context = historyProvider()->build($contact->fresh());

    expect($context->activeSafetyBodyRegions)->toContain('knee');
});

// ── 37/38: determinismo y pureza de lectura ──

it('37: the same history produces exactly the same context on repeated calls (determinism)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    performedExercise($session, Exercise::factory()->create(), [['reps' => 10, 'load' => 20]]);

    $provider = historyProvider();
    $first = $provider->build($contact->fresh());
    $second = $provider->build($contact->fresh());

    expect($first->windowSessionsCount)->toBe($second->windowSessionsCount);
    expect($first->aggregates->lastLoadByExerciseId)->toBe($second->aggregates->lastLoadByExerciseId);
    expect($first->sessions[0]->exercises[0]->name)->toBe($second->sessions[0]->exercises[0]->name);
});

it('38: building the context never modifies any source-of-truth table', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    performedExercise($session, Exercise::factory()->create(), [['reps' => 10, 'load' => 20]]);

    $before = [
        WorkoutSession::count(), WorkoutExercise::count(), ExerciseLog::count(), ExerciseSet::count(),
        TrainingProfile::query()->first()->updated_at,
    ];

    historyProvider()->build($contact->fresh());

    expect(WorkoutSession::count())->toBe($before[0]);
    expect(WorkoutExercise::count())->toBe($before[1]);
    expect(ExerciseLog::count())->toBe($before[2]);
    expect(ExerciseSet::count())->toBe($before[3]);
    expect(TrainingProfile::query()->first()->updated_at)->toEqual($before[4]);
});

// ── 39: sin IA ──

it('39: TrainingHistoryContextProvider never references any AI service', function () {
    $source = file_get_contents(app_path('Training/Support/TrainingHistoryContextProvider.php'));

    expect($source)->not->toContain('AiServiceInterface');
    expect($source)->not->toContain('AIServiceFactory');
    expect($source)->not->toContain('getResponse');
});

// ── 40: sin N+1 evidente ──

it('40: query count does not grow with the number of exercises/sets (no obvious N+1)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    performedExercise($session, Exercise::factory()->create(), [['reps' => 10, 'load' => 20]]);

    DB::enableQueryLog();
    historyProvider()->build($contact->fresh());
    $smallQueryCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    // Escenario mucho más grande: 6 sesiones, 5 ejercicios cada una, 3 sets cada uno.
    $contact2 = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact2->id]);
    for ($s = 0; $s < 6; $s++) {
        $session2 = WorkoutSession::factory()->completed()->create(['contact_id' => $contact2->id, 'scheduled_at' => now()->subDays($s)]);
        for ($e = 0; $e < 5; $e++) {
            performedExercise($session2, Exercise::factory()->create(), [
                ['reps' => 10, 'load' => 20], ['reps' => 10, 'load' => 20], ['reps' => 10, 'load' => 20],
            ], loggedAt: now()->subDays($s));
        }
    }

    // Todas las inserciones de datos de prueba anteriores quedaron
    // registradas también (el log seguía habilitado) — se descartan para
    // medir únicamente las queries de build().
    DB::flushQueryLog();
    historyProvider()->build($contact2->fresh());
    $largeQueryCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    expect($largeQueryCount)->toBe($smallQueryCount);
});
