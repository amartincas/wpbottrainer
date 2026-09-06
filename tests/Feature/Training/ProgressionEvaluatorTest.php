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
use App\Training\Enums\ProgressionDecision;
use App\Training\Enums\ProgressionEffortSignal;
use App\Training\Enums\ProgressionIntensitySignal;
use App\Training\Enums\RestrictionStatus;
use App\Training\Enums\TrackingType;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingHistoryContextProvider;
use Illuminate\Support\Facades\DB;

/**
 * Bloque 7 (D050) — ProgressionEvaluator: dirección de progresión
 * determinista, sin IA, sobre el contexto histórico del Bloque 6 (D049).
 * Ver docs/DECISIONS.md.
 */
function historyProviderForProgression(): TrainingHistoryContextProvider
{
    return new TrainingHistoryContextProvider(new SafetyRestrictionResolver(new BodyRegionCanonicalMapper));
}

function progressionEvaluator(): ProgressionEvaluator
{
    return new ProgressionEvaluator;
}

/**
 * Crea una ejecución "realizada" con control total sobre prescripción y
 * resultado real, necesario para fijar con precisión Improved/AtRecentBest/
 * BelowRecentBest/Met/Below/Unknown en cada test.
 *
 * @param  array<int, array{reps?: ?int, load?: ?float, duration?: ?int}>  $sets
 */
function pExecution(
    WorkoutSession $session,
    Exercise $exercise,
    array $sets,
    ?int $rpe = null,
    ?int $prescribedReps = 10,
    ?int $prescribedSets = null,
    ?float $prescribedLoad = null,
    ?\Carbon\CarbonInterface $loggedAt = null,
): WorkoutExercise {
    $we = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_reps' => $prescribedReps,
        'prescribed_sets' => $prescribedSets,
        'prescribed_load' => $prescribedLoad,
    ]);

    $log = ExerciseLog::factory()->create([
        'workout_exercise_id' => $we->id,
        'rpe' => $rpe,
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

    return $we;
}

function pSkipped(WorkoutSession $session, Exercise $exercise): WorkoutExercise
{
    $we = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
    ]);

    ExerciseLog::factory()->create(['workout_exercise_id' => $we->id, 'skip_reason' => 'cant_do', 'note' => 'No realizado.']);

    return $we;
}

/**
 * Sesiones separadas por días, más recientes primero en el orden de
 * creación no importa — scheduled_at es lo único que determina el orden.
 */
function pSession(Contact $contact, int $daysAgo): WorkoutSession
{
    return WorkoutSession::factory()->completed()->create([
        'contact_id' => $contact->id,
        'scheduled_at' => now()->subDays($daysAgo),
    ]);
}

function buildProgressionContext(Contact $contact): \App\Training\Support\TrainingHistoryContext
{
    return historyProviderForProgression()->build($contact->fresh());
}

// ── 1-3: gate de evidencia ──

it('1: no history yields insufficient_data (no_history)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->decision)->toBe(ProgressionDecision::InsufficientData);
    expect($eval->reasonCodes)->toContain('no_history');
    expect($eval->metrics->executionsConsidered)->toBe(0);
});

it('2a: a single execution with a usable signal yields maintain (single_execution_baseline)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();
    $session = pSession($contact, 1);
    pExecution($session, $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->decision)->toBe(ProgressionDecision::Maintain);
    expect($eval->reasonCodes)->toContain('single_execution_baseline');
});

it('2b: a single execution with zero usable signals yields insufficient_data (single_execution_no_data)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();
    $session = pSession($contact, 1);
    // Sin RPE, sin carga, sin prescribedReps -> ninguna señal usable.
    pExecution($session, $exercise, [['reps' => null, 'load' => null]], rpe: null, prescribedReps: null);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->decision)->toBe(ProgressionDecision::InsufficientData);
    expect($eval->reasonCodes)->toContain('single_execution_no_data');
});

it('3: several executions are all considered (executionsConsidered matches count)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 3), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5);
    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->executionsConsidered)->toBe(3);
});

// ── Improved / AtRecentBest / BelowRecentBest ──

it('4: last execution above the best PRIOR execution yields Improved and can progress', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 35]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->intensitySignal)->toBe(ProgressionIntensitySignal::Improved);
    expect($eval->metrics->lastIntensity)->toBe(35.0);
    expect($eval->metrics->bestPriorIntensity)->toBe(30.0);
    expect($eval->decision)->toBe(ProgressionDecision::Progress);
    expect($eval->reasonCodes)->toContain('consistent_controlled_performance_improved');
});

it('5: last execution equal to the best PRIOR execution yields AtRecentBest, not Improved, and can still progress', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->intensitySignal)->toBe(ProgressionIntensitySignal::AtRecentBest);
    expect($eval->decision)->toBe(ProgressionDecision::Progress);
    expect($eval->reasonCodes)->toContain('consistent_controlled_performance_at_best');
});

it('6: last execution below the best PRIOR execution yields BelowRecentBest', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->intensitySignal)->toBe(ProgressionIntensitySignal::BelowRecentBest);
    expect($eval->decision)->toBe(ProgressionDecision::Maintain);
    expect($eval->reasonCodes)->toContain('below_recent_best_no_corroboration');
});

it('7: BelowRecentBest with excessive effort yields reduce (high_effort_with_shortfall)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 9, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->decision)->toBe(ProgressionDecision::Reduce);
    expect($eval->reasonCodes)->toContain('high_effort_with_shortfall');
});

// ── carga null / carga 0 / última sin carga ──

it('8: actual_load null on the last execution yields intensity Unknown, never searching backward', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => null]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->intensitySignal)->toBe(ProgressionIntensitySignal::Unknown);
    expect($eval->metrics->lastIntensity)->toBeNull();
    expect($eval->metrics->bestPriorIntensity)->toBe(40.0);
    expect($eval->reasonCodes)->toContain('intensity_unknown_last_execution');
});

it('9: actual_load 0 is a real value, distinct from absence', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 5]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 0]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->lastIntensity)->toBe(0.0);
    expect($eval->metrics->intensitySignal)->toBe(ProgressionIntensitySignal::BelowRecentBest);
});

// ── duración ──

it('10: duration-based exercises use max(actual_duration_seconds) per execution, never mixed with load', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->timeBased()->create();

    pExecution(pSession($contact, 2), $exercise, [['duration' => 30]], rpe: 5, prescribedReps: null);
    pExecution(pSession($contact, 1), $exercise, [['duration' => 45]], rpe: 5, prescribedReps: null);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::TimeBased);

    expect($eval->metrics->lastIntensity)->toBe(45.0);
    expect($eval->metrics->bestPriorIntensity)->toBe(30.0);
    expect($eval->metrics->intensitySignal)->toBe(ProgressionIntensitySignal::Improved);
});

it('10b: no execution in the window has intensity data at all yields NotApplicable', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    // Ejercicio RepsAndLoad pero nunca se registró carga en ninguna ejecución.
    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => null]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => null]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->intensitySignal)->toBe(ProgressionIntensitySignal::NotApplicable);
});

// ── reps: completas / incompletas / superiores / sets faltantes / prescripción ausente ──

it('11: reps meeting exactly the prescription yield Met', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10, prescribedSets: 1);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10, prescribedSets: 1);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->repsMetOnMostRecent)->toBeTrue();
    expect($eval->reasonCodes)->toContain('reps_met_or_exceeded');
});

it('12: reps below the prescription (minimum across sets) yield Below', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10, prescribedSets: 1);
    pExecution(pSession($contact, 1), $exercise, [
        ['reps' => 10, 'load' => 40], ['reps' => 6, 'load' => 40],
    ], rpe: 5, prescribedReps: 10, prescribedSets: 2);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->repsMetOnMostRecent)->toBeFalse();
    expect($eval->reasonCodes)->toContain('reps_below_prescribed');
});

it('13: reps exceeding the prescription are classified as Met, not a separate state', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10, prescribedSets: 1);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 15, 'load' => 40]], rpe: 5, prescribedReps: 10, prescribedSets: 1);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->repsMetOnMostRecent)->toBeTrue();
});

it('14: fewer executed sets than prescribed yields Below, regardless of the reps performed', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10, prescribedSets: 1);
    // Solo 1 set ejecutado, con reps excelentes, pero se prescribieron 3.
    pExecution(pSession($contact, 1), $exercise, [['reps' => 20, 'load' => 40]], rpe: 5, prescribedReps: 10, prescribedSets: 3);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->repsMetOnMostRecent)->toBeFalse();
    expect($eval->reasonCodes)->toContain('fewer_sets_than_prescribed');
});

it('15: extra executed sets beyond the prescription do not disqualify Met by themselves', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10, prescribedSets: 1);
    pExecution(pSession($contact, 1), $exercise, [
        ['reps' => 10, 'load' => 40], ['reps' => 12, 'load' => 40], ['reps' => 11, 'load' => 40],
    ], rpe: 5, prescribedReps: 10, prescribedSets: 2);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->repsMetOnMostRecent)->toBeTrue();
});

it('16: a null prescribedReps yields Unknown compliance, never assumed', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 40]], rpe: 5, prescribedReps: null);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->repsMetOnMostRecent)->toBeNull();
    expect($eval->reasonCodes)->toContain('prescribed_reps_missing');
});

it('16b: no actual_reps recorded on the last execution yields Unknown compliance (reps_data_missing)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->timeBased()->create();

    pExecution(pSession($contact, 2), $exercise, [['duration' => 30]], rpe: 5, prescribedReps: null);
    pExecution(pSession($contact, 1), $exercise, [['duration' => 30, 'reps' => null]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::TimeBased);

    expect($eval->metrics->repsMetOnMostRecent)->toBeNull();
    expect($eval->reasonCodes)->toContain('reps_data_missing');
});

// ── RPE: bandas y correlación temporal ──

it('17: RPE 1-6 on the last execution yields Controlled', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 6, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->effortSignal)->toBe(ProgressionEffortSignal::Controlled);
});

it('18: RPE 7-8 on the last execution yields Elevated', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 8, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->effortSignal)->toBe(ProgressionEffortSignal::Elevated);
    expect($eval->decision)->toBe(ProgressionDecision::Maintain);
    expect($eval->reasonCodes)->toContain('elevated_effort_stable_performance');
});

it('19: RPE 9-10 on the last execution yields Excessive', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 10, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->effortSignal)->toBe(ProgressionEffortSignal::Excessive);
});

it('20: a missing RPE on the last execution yields Unknown, never blocking progress by itself', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: null, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 35]], rpe: null, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->effortSignal)->toBe(ProgressionEffortSignal::Unknown);
    expect($eval->metrics->lastExecutionRpe)->toBeNull();
    expect($eval->decision)->toBe(ProgressionDecision::Progress);
});

it('21: an RPE recorded on an earlier execution is never borrowed for the most recent one', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    // Ejecución anterior con RPE excesivo; la más reciente NO registra RPE.
    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 10, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: null, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->effortSignal)->toBe(ProgressionEffortSignal::Unknown);
    expect($eval->metrics->lastExecutionRpe)->toBeNull();
    // No debe producirse reduce solo porque una ejecución anterior tuvo RPE alto.
    expect($eval->decision)->not->toBe(ProgressionDecision::Reduce);
});

it('22: intensity and RPE used in a single decision always come from the same execution', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    // Ejecución anterior: RPE excesivo pero SIN caída de carga en sí misma (no importa).
    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 40]], rpe: 10, prescribedReps: 10);
    // Última ejecución: carga por debajo de la mejor previa, pero RPE controlado.
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    // Sin la corrección, el RPE excesivo "prestado" de la ejecución anterior
    // combinado con la caída de carga real habría producido reduce.
    expect($eval->metrics->effortSignal)->toBe(ProgressionEffortSignal::Controlled);
    expect($eval->decision)->toBe(ProgressionDecision::Maintain);
    expect($eval->reasonCodes)->toContain('below_recent_best_no_corroboration');
});

// ── datos contradictorios ──

it('23: excessive effort with stable-or-better performance yields maintain (contradictory_signals), not insufficient_data', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10, prescribedSets: 1);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 35]], rpe: 10, prescribedReps: 10, prescribedSets: 1);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->decision)->toBe(ProgressionDecision::Maintain);
    expect($eval->reasonCodes)->toContain('contradictory_signals');
});

// ── skips ──

it('24: a skipped execution never enters the quantitative signals and never penalizes', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 3), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    pSkipped(pSession($contact, 2), $exercise);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 35]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->executionsConsidered)->toBe(2); // el skip no cuenta
    expect($eval->decision)->toBe(ProgressionDecision::Progress);
    expect($eval->reasonCodes)->not->toContain('most_recent_execution_skipped');
});

it('25: when the most recent entry overall was skipped, an informational reason code is attached without changing the decision', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    pSkipped(pSession($contact, 1), $exercise);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->reasonCodes)->toContain('most_recent_execution_skipped');
    // La decisión sigue basada en la única ejecución Performed disponible.
    expect($eval->decision)->toBe(ProgressionDecision::Maintain);
    expect($eval->reasonCodes)->toContain('single_execution_baseline');
});

// ── ventana / snapshot / eliminado / inactivo ──

it('26: the evaluator only reflects what the passed context already includes (no independent window)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    // Fuera de la ventana de 4 semanas del Bloque 6 -> no debe contarse.
    pExecution(pSession($contact, 40), $exercise, [['reps' => 10, 'load' => 100]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->executionsConsidered)->toBe(1);
    expect($eval->metrics->bestPriorIntensity)->toBeNull();
});

it('27: evaluation groups strictly by exerciseId, never by name (historical snapshot)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create(['name' => 'Nombre original']);

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    $exercise->update(['name' => 'Nombre cambiado']);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 35]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->executionsConsidered)->toBe(2);
    expect($eval->metrics->intensitySignal)->toBe(ProgressionIntensitySignal::Improved);
});

it('28: an unmatched exerciseId (orphaned by deletion) yields insufficient_data, never a crash', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    $deletedExerciseId = $exercise->id;
    $exercise->delete(); // nullOnDelete: exercise_id de esa fila ahora es null

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $deletedExerciseId, TrackingType::RepsAndLoad);

    expect($eval->decision)->toBe(ProgressionDecision::InsufficientData);
    expect($eval->reasonCodes)->toContain('no_history');
});

it('29: inactivity of the live Exercise does not affect the evaluation', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 35]], rpe: 5, prescribedReps: 10);
    $exercise->update(['is_active' => false]);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->decision)->toBe(ProgressionDecision::Progress);
});

// ── seguridad ──

it('30: an existing confirmed TrainingRestriction does not alter the evaluation', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();
    TrainingRestriction::factory()->create([
        'contact_id' => $contact->id,
        'body_region' => BodyRegion::Knee,
        'status' => RestrictionStatus::Confirmed,
    ]);

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 35]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->decision)->toBe(ProgressionDecision::Progress);
});

it('30b: reduce is never triggered by skip_reason or safety data alone', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();
    TrainingRestriction::factory()->create([
        'contact_id' => $contact->id,
        'body_region' => BodyRegion::Knee,
        'status' => RestrictionStatus::Confirmed,
    ]);

    pExecution(pSession($contact, 3), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    pSkipped(pSession($contact, 2), $exercise);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->decision)->not->toBe(ProgressionDecision::Reduce);
});

// ── pureza, determinismo, sin IA, sin dependencias prohibidas ──

it('31: evaluate() never writes to any table', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);

    $before = [WorkoutSession::count(), WorkoutExercise::count(), ExerciseLog::count(), ExerciseSet::count()];

    progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect(WorkoutSession::count())->toBe($before[0]);
    expect(WorkoutExercise::count())->toBe($before[1]);
    expect(ExerciseLog::count())->toBe($before[2]);
    expect(ExerciseSet::count())->toBe($before[3]);
});

it('32: the same context yields the same evaluation on repeated calls (determinism)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();
    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 35]], rpe: 5, prescribedReps: 10);

    $context = buildProgressionContext($contact);
    $evaluator = progressionEvaluator();

    $first = $evaluator->evaluate($context, $exercise->id, TrackingType::RepsAndLoad);
    $second = $evaluator->evaluate($context, $exercise->id, TrackingType::RepsAndLoad);

    expect($first->decision)->toBe($second->decision);
    expect($first->reasonCodes)->toBe($second->reasonCodes);
    expect($first->metrics->lastIntensity)->toBe($second->metrics->lastIntensity);
});

it('33: ProgressionEvaluator never references any AI service, Eloquent model, or WhatsApp', function () {
    $source = file_get_contents(app_path('Training/Support/ProgressionEvaluator.php'));

    expect($source)->not->toContain('AiServiceInterface');
    expect($source)->not->toContain('AIServiceFactory');
    expect($source)->not->toContain('getResponse');
    expect($source)->not->toContain('use App\Models');
    expect($source)->not->toContain('WhatsApp');
    expect($source)->not->toContain('DB::');
});

it('34: ProgressionEvaluator never references anti-repetition logic', function () {
    $source = file_get_contents(app_path('Training/Support/ProgressionEvaluator.php'));

    expect($source)->not->toContain('ANTI_REPETITION');
    expect($source)->not->toContain('recentlyUsedExerciseIds');
});

it('35: ProgressionEvaluator never imports/uses the WorkoutExercise model or writes prescribed_*', function () {
    $source = file_get_contents(app_path('Training/Support/ProgressionEvaluator.php'));

    // Se busca uso real de código (import o llamada estática), no la mera
    // mención en un docblock explicando precisamente que NO lo hace.
    expect($source)->not->toContain('use App\Models\WorkoutExercise');
    expect($source)->not->toContain('WorkoutExercise::');
    expect($source)->not->toContain('::create(');
    expect($source)->not->toContain('::update(');
    expect($source)->not->toContain('prescribed_reps =');
    expect($source)->not->toContain('prescribed_load =');
});

it('36: every decision carries at least one documented reason code', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->reasonCodes)->not->toBeEmpty();
});

it('37: insufficient_data with two performed executions but no usable signals at all (no_usable_signals)', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->timeBased()->create();

    // TimeBased, sin duración registrada nunca, sin RPE, sin prescribedReps.
    pExecution(pSession($contact, 2), $exercise, [['duration' => null]], rpe: null, prescribedReps: null);
    pExecution(pSession($contact, 1), $exercise, [['duration' => null]], rpe: null, prescribedReps: null);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::TimeBased);

    expect($eval->decision)->toBe(ProgressionDecision::InsufficientData);
    expect($eval->reasonCodes)->toContain('no_usable_signals');
});

it('38: bodyweight exercise (NotApplicable intensity) with reps compliance met yields progress', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    pExecution(pSession($contact, 2), $exercise, [['reps' => 10, 'load' => null]], rpe: 5, prescribedReps: 10, prescribedSets: 1);
    pExecution(pSession($contact, 1), $exercise, [['reps' => 12, 'load' => null]], rpe: 5, prescribedReps: 10, prescribedSets: 1);

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->metrics->intensitySignal)->toBe(ProgressionIntensitySignal::NotApplicable);
    expect($eval->decision)->toBe(ProgressionDecision::Progress);
    expect($eval->reasonCodes)->toContain('reps_target_met_no_load_signal');
});

it('39: real Block 6 context (via TrainingHistoryContextProvider) is compatible with the evaluator end-to-end', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();

    for ($i = 3; $i >= 1; $i--) {
        pExecution(pSession($contact, $i), $exercise, [['reps' => 10, 'load' => 30 + (3 - $i) * 5]], rpe: 5, prescribedReps: 10, prescribedSets: 1);
    }

    $eval = progressionEvaluator()->evaluate(buildProgressionContext($contact), $exercise->id, TrackingType::RepsAndLoad);

    expect($eval->exerciseId)->toBe($exercise->id);
    expect($eval->sourceWindowSessions)->toBe(3);
    expect($eval->sourceWindowWeeks)->toBe(4);
    expect($eval->decision)->toBe(ProgressionDecision::Progress);
});

it('40: query count stays at zero — the evaluator issues no SQL of its own', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $exercise = Exercise::factory()->create();
    pExecution(pSession($contact, 1), $exercise, [['reps' => 10, 'load' => 30]], rpe: 5, prescribedReps: 10);

    $context = buildProgressionContext($contact);

    DB::enableQueryLog();
    progressionEvaluator()->evaluate($context, $exercise->id, TrackingType::RepsAndLoad);
    $queryCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    expect($queryCount)->toBe(0);
});
