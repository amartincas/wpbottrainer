<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\ExerciseSet;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\DurationEstimator;
use App\Training\Support\MultipleActiveWorkoutSessionsException;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\RequestedFocusGroup;
use App\Training\Support\ReplaceWorkoutSessionService;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrainingCatalogInsufficientException;
use App\Training\Support\TrainingHistoryContextProvider;
use App\Training\Engine\TrainingEngine;

/**
 * Hito B2 (Nueva rutina durante sesión activa) — tests del servicio de
 * orquestación puro, aislado de Router/CoachService/ExecutionReportService
 * (esos se cubren en NewWorkoutRequestConversationalWiringTest.php).
 * Prefijo "nwr" en los helpers para evitar colisión de funciones globales
 * con otros archivos de test en el mismo proceso Pest.
 */
function nwrReadyContact(array $profileOverrides = []): Contact
{
    $contact = Contact::factory()->create();

    TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'split_type' => SplitType::FullBody,
        'goal' => TrainingGoal::GeneralFitness,
        'experience_level' => ExperienceLevel::Beginner,
    ], $profileOverrides));

    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

function nwrEngine(): TrainingEngine
{
    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);

    return new TrainingEngine(
        new TrainingAccessGate,
        $safetyResolver,
        new TrainingHistoryContextProvider($safetyResolver),
        new ProgressionEvaluator,
        new DurationEstimator,
    );
}

function nwrService(): ReplaceWorkoutSessionService
{
    return new ReplaceWorkoutSessionService(nwrEngine());
}

/**
 * `count` ejercicios `beginner`, activos, con `primary_muscle` = $muscle,
 * sin equipo — mismo criterio de uniformidad que RequestedFocusSelectionTest.php.
 */
function nwrExercises(string $muscle, int $count, array $overrides = []): void
{
    for ($i = 0; $i < $count; $i++) {
        Exercise::factory()->create(array_merge([
            'muscle_group' => 'core',
            'primary_muscle' => MuscleFocus::from($muscle),
            'difficulty_level' => 'beginner',
            'equipment_needed' => [],
        ], $overrides));
    }
}

// ── Caso 1: Scheduled sin ejercicios entregados ─────────────────────────

it('1: replaces a Scheduled session with no exercises delivered yet — old=Superseded, new=Scheduled, linked', function () {
    nwrExercises('chest', 4);
    $contact = nwrReadyContact();
    $old = nwrEngine()->decideNextSession($contact->fresh());

    $new = nwrService()->replace($contact->fresh(), null);

    expect($new)->not->toBeNull();
    expect($new->id)->not->toBe($old->id);
    expect($new->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Superseded);
    expect($old->fresh()->superseded_by_id)->toBe($new->id);
});

// ── Caso 2: Preparation entregada ───────────────────────────────────────

it('2: replaces a session with a delivered Preparation exercise — replaced entirely', function () {
    nwrExercises('chest', 4);
    Exercise::factory()->create([
        'exercise_type' => ['warmup'],
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);
    $contact = nwrReadyContact();
    $old = nwrEngine()->decideNextSession($contact->fresh());
    $prep = $old->workoutExercises->firstWhere('phase', WorkoutExercisePhase::Preparation);

    if ($prep !== null) {
        $prep->update(['delivered_at' => now()]);
    }

    $new = nwrService()->replace($contact->fresh(), null);

    expect($new->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Superseded);
});

// ── Caso 3: algunos Main entregados/reportados — historial intacto ──────

it('3: replaces a session with some Main exercises already reported — ExerciseLog history stays intact', function () {
    nwrExercises('chest', 4);
    $contact = nwrReadyContact();
    $old = nwrEngine()->decideNextSession($contact->fresh());
    $main = $old->workoutExercises->where('phase', WorkoutExercisePhase::Main)->first();
    $main->update(['delivered_at' => now()]);

    $log = ExerciseLog::create([
        'workout_exercise_id' => $main->id,
        'rpe' => 7,
        'note' => null,
        'skip_reason' => null,
        'logged_at' => now(),
    ]);
    ExerciseSet::create(['exercise_log_id' => $log->id, 'set_number' => 1, 'actual_reps' => 10, 'actual_load' => 40, 'actual_duration_seconds' => null]);

    $new = nwrService()->replace($contact->fresh(), null);

    expect($new->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Superseded);

    // Historial intacto — nunca borrado/reescrito.
    expect(WorkoutExercise::where('id', $main->id)->exists())->toBeTrue();
    expect(ExerciseLog::find($log->id))->not->toBeNull();
    expect(ExerciseSet::where('exercise_log_id', $log->id)->count())->toBe(1);
});

// ── Casos 6-9: requested focus — precedencia ────────────────────────────

it('6: explicit requested focus on replacement -> new session uses it', function () {
    nwrExercises('chest', 4);
    nwrExercises('back', 4);
    $contact = nwrReadyContact();
    nwrEngine()->decideNextSession($contact->fresh());

    $new = nwrService()->replace($contact->fresh(), [new RequestedFocusGroup('chest', ['chest'])]);

    expect($new->prescription_context_snapshot['requested_focus'])->toBe([
        ['key' => 'chest', 'muscles' => ['chest']],
    ]);
});

it('7: no explicit focus, old session had one -> inherited into the new session', function () {
    nwrExercises('chest', 4);
    nwrExercises('back', 4);
    $contact = nwrReadyContact();
    nwrEngine()->decideNextSession($contact->fresh(), [new RequestedFocusGroup('chest', ['chest'])]);

    $new = nwrService()->replace($contact->fresh(), null);

    expect($new->prescription_context_snapshot['requested_focus'])->toBe([
        ['key' => 'chest', 'muscles' => ['chest']],
    ]);
});

it('8: explicit new focus overrides the one inherited from the old session', function () {
    nwrExercises('chest', 4);
    nwrExercises('back', 4);
    $contact = nwrReadyContact();
    nwrEngine()->decideNextSession($contact->fresh(), [new RequestedFocusGroup('chest', ['chest'])]);

    $new = nwrService()->replace($contact->fresh(), [new RequestedFocusGroup('back', ['back'])]);

    expect($new->prescription_context_snapshot['requested_focus'])->toBe([
        ['key' => 'back', 'muscles' => ['back']],
    ]);
});

it('9: old session had no requested focus, no explicit new one -> autonomous focus (requested_focus empty)', function () {
    nwrExercises('chest', 4);
    $contact = nwrReadyContact();
    nwrEngine()->decideNextSession($contact->fresh());

    $new = nwrService()->replace($contact->fresh(), null);

    expect($new->prescription_context_snapshot['requested_focus'])->toBe([]);
});

// ── Caso 10: TrainingProfile no muta ────────────────────────────────────

it('10: replacing with an explicit requested focus never mutates TrainingProfile.primary_focus/secondary_focus', function () {
    nwrExercises('chest', 4);
    $contact = nwrReadyContact();
    nwrEngine()->decideNextSession($contact->fresh());
    $profileBefore = $contact->fresh()->trainingProfile;

    nwrService()->replace($contact->fresh(), [new RequestedFocusGroup('chest', ['chest'])]);

    $profileAfter = $contact->fresh()->trainingProfile;
    expect($profileAfter->primary_focus)->toBe($profileBefore->primary_focus);
    expect($profileAfter->secondary_focus)->toBe($profileBefore->secondary_focus);
});

// ── Caso 13: 0 Scheduled ─────────────────────────────────────────────────

it('13: no Scheduled session exists -> replace() returns null, nothing is created or changed', function () {
    $contact = nwrReadyContact();

    $result = nwrService()->replace($contact->fresh(), null);

    expect($result)->toBeNull();
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(0);
});

// ── Caso 14: >1 Scheduled ────────────────────────────────────────────────

it('14: more than one Scheduled session -> throws MultipleActiveWorkoutSessionsException, never picks one arbitrarily', function () {
    $contact = nwrReadyContact();
    WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->create(['contact_id' => $contact->id]);

    expect(fn () => nwrService()->replace($contact->fresh(), null))
        ->toThrow(MultipleActiveWorkoutSessionsException::class);

    // Ninguna de las dos se tocó.
    expect(WorkoutSession::where('contact_id', $contact->id)->where('status', WorkoutSessionStatus::Scheduled)->count())->toBe(2);
});

// ── Caso 15: falla la creación de la nueva -> rollback completo ────────

it('15: if creating the replacement fails, the old session stays Scheduled and unlinked (full rollback)', function () {
    // Sin catálogo elegible en absoluto -> TrainingCatalogInsufficientException
    // dentro de decideNextSession(), lanzada DESDE dentro de la transacción
    // de replace().
    $contact = nwrReadyContact();
    $old = WorkoutSession::factory()->create(['contact_id' => $contact->id]);

    expect(fn () => nwrService()->replace($contact->fresh(), null))
        ->toThrow(TrainingCatalogInsufficientException::class);

    expect($old->fresh()->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect($old->fresh()->superseded_by_id)->toBeNull();
});

// ── Caso 16 (aproximación secuencial — ver limitación documentada) ─────

it('16 (secuencial): after a successful replace, at most one Scheduled session ever exists for the contact', function () {
    nwrExercises('chest', 4);
    $contact = nwrReadyContact();
    $old = nwrEngine()->decideNextSession($contact->fresh());

    $first = nwrService()->replace($contact->fresh(), null);

    expect(WorkoutSession::where('contact_id', $contact->id)->where('status', WorkoutSessionStatus::Scheduled)->count())->toBe(1);
    expect(WorkoutSession::where('contact_id', $contact->id)->where('status', WorkoutSessionStatus::Scheduled)->first()->id)->toBe($first->id);

    // Nota de alcance: esto verifica el invariante "nunca 2 Scheduled
    // simultáneas" tras una secuencia de llamadas reales — NO reproduce la
    // condición de carrera de dos transacciones verdaderamente concurrentes
    // (RefreshDatabase envuelve todo el test en una única transacción sobre
    // la conexión por defecto, lo que impide una segunda conexión real con
    // datos no confirmados visibles). La protección real (lockForUpdate +
    // relectura post-bloqueo de InnoDB) se documenta y se razona en el
    // docblock de ReplaceWorkoutSessionService — ver también el reporte
    // final de este hito.
});
