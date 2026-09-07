<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\ExerciseSet;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\SplitType;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrainingHistoryContextProvider;
use Illuminate\Support\Facades\DB;

/**
 * Bloque 8 (D051) — integración de ProgressionEvaluator en TrainingEngine.
 * Nombres deliberadamente distintos a los helpers de TrainingEngineTest.php
 * para evitar colisión de funciones globales entre archivos de test.
 */
function readyContactForProgressionIntegration(array $profileOverrides = []): Contact
{
    $contact = Contact::factory()->create();

    TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'split_type' => SplitType::FullBody,
    ], $profileOverrides));

    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

function progressionIntegrationEngine(): TrainingEngine
{
    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);

    return new TrainingEngine(
        new TrainingAccessGate,
        $safetyResolver,
        new TrainingHistoryContextProvider($safetyResolver),
        new ProgressionEvaluator,
    );
}

// ── 1: sin historial ──

it('1: no history yields the existing GOAL_DEFAULTS initial prescription (insufficient_data)', function () {
    // goal fijado explícitamente: GOAL_DEFAULTS varía sets/reps por objetivo.
    $contact = readyContactForProgressionIntegration(['goal' => \App\Training\Enums\TrainingGoal::GeneralFitness]);
    Exercise::factory()->create(['muscle_group' => 'chest']);

    $session = progressionIntegrationEngine()->decideNextSession($contact);
    $we = $session->workoutExercises->first();

    expect($we->prescribed_sets)->toBe(3);
    expect($we->prescribed_reps)->toBe(10);
    expect($we->prescribed_load)->toBeNull();
});

// ── 2: maintain conserva la lógica numérica existente ──

it('2: maintain carries the real last-executed intensity forward, unchanged', function () {
    $contact = readyContactForProgressionIntegration();
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    $older = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
    $olderWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $older->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 30,
    ]);
    $olderLog = ExerciseLog::factory()->create(['workout_exercise_id' => $olderWe->id, 'rpe' => 7, 'logged_at' => now()->subDays(2)]);
    ExerciseSet::factory()->create(['exercise_log_id' => $olderLog->id, 'actual_reps' => 10, 'actual_load' => 30]);

    $recent = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    $recentWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $recent->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 35,
    ]);
    // RPE elevado (7): ni Controlled ni Excessive -> maintain, nunca progress.
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $recentWe->id, 'rpe' => 7, 'logged_at' => now()->subDay()]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 10, 'actual_load' => 35]);

    $session = progressionIntegrationEngine()->decideNextSession($contact);
    $we = $session->workoutExercises->firstWhere('exercise_id', $exercise->id);

    expect((float) $we->prescribed_load)->toBe(35.0); // sin incremento
});

// ── 3: progress utiliza la dirección del evaluator (AtRecentBest) ──

it('3: progress fires from AtRecentBest (matching, not exceeding, the prior best) with controlled effort', function () {
    $contact = readyContactForProgressionIntegration();
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    $older = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
    $olderWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $older->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 40,
    ]);
    $olderLog = ExerciseLog::factory()->create(['workout_exercise_id' => $olderWe->id, 'rpe' => 5, 'logged_at' => now()->subDays(2)]);
    ExerciseSet::factory()->create(['exercise_log_id' => $olderLog->id, 'actual_reps' => 10, 'actual_load' => 40]);

    $recent = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    $recentWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $recent->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 40,
    ]);
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $recentWe->id, 'rpe' => 5, 'logged_at' => now()->subDay()]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 10, 'actual_load' => 40]); // igual, no supera

    $session = progressionIntegrationEngine()->decideNextSession($contact);
    $we = $session->workoutExercises->firstWhere('exercise_id', $exercise->id);

    expect((float) $we->prescribed_load)->toBe(42.5); // 40 + 2.5, vía AtRecentBest
});

// ── 4: reduce -> comportamiento conservador (equivalente a maintain en v1) ──

it('4: reduce does not invent a decrement — it holds the current value, exactly like maintain', function () {
    $contact = readyContactForProgressionIntegration();
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    $older = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
    $olderWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $older->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 40,
    ]);
    $olderLog = ExerciseLog::factory()->create(['workout_exercise_id' => $olderWe->id, 'rpe' => 5, 'logged_at' => now()->subDays(2)]);
    ExerciseSet::factory()->create(['exercise_log_id' => $olderLog->id, 'actual_reps' => 10, 'actual_load' => 40]);

    $recent = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    $recentWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $recent->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 40,
    ]);
    // Esfuerzo excesivo (9) + carga por debajo de la mejor previa -> reduce.
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $recentWe->id, 'rpe' => 9, 'logged_at' => now()->subDay()]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 10, 'actual_load' => 30]);

    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);
    $context = (new TrainingHistoryContextProvider($safetyResolver))->build($contact->fresh());
    $evaluation = (new ProgressionEvaluator)->evaluate($context, $exercise->id, \App\Training\Enums\TrackingType::RepsAndLoad);
    expect($evaluation->decision)->toBe(\App\Training\Enums\ProgressionDecision::Reduce);

    $session = progressionIntegrationEngine()->decideNextSession($contact);
    $we = $session->workoutExercises->firstWhere('exercise_id', $exercise->id);

    // Nunca -2.5, nunca un porcentaje: se sostiene el valor real actual (30).
    expect((float) $we->prescribed_load)->toBe(30.0);
});

// ── 5: duración sigue separada de carga, en cualquier dirección ──

it('5: time-based exercises never populate reps/load, regardless of the direction', function () {
    $contact = readyContactForProgressionIntegration();
    $exercise = Exercise::factory()->timeBased()->create(['muscle_group' => 'core']);

    $session = progressionIntegrationEngine()->decideNextSession($contact);
    $we = $session->workoutExercises->first();

    expect($we->prescribed_reps)->toBeNull();
    expect($we->prescribed_load)->toBeNull();
    expect($we->prescribed_duration_seconds)->not->toBeNull();
});

// ── 6: RPE ausente no bloquea progress si el resto corrobora ──

it('6: a missing RPE on the most recent execution does not block progress by itself', function () {
    $contact = readyContactForProgressionIntegration();
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    $older = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
    $olderWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $older->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 30,
    ]);
    $olderLog = ExerciseLog::factory()->create(['workout_exercise_id' => $olderWe->id, 'rpe' => null, 'logged_at' => now()->subDays(2)]);
    ExerciseSet::factory()->create(['exercise_log_id' => $olderLog->id, 'actual_reps' => 10, 'actual_load' => 30]);

    $recent = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    $recentWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $recent->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 35,
    ]);
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $recentWe->id, 'rpe' => null, 'logged_at' => now()->subDay()]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 10, 'actual_load' => 35]);

    $session = progressionIntegrationEngine()->decideNextSession($contact);
    $we = $session->workoutExercises->firstWhere('exercise_id', $exercise->id);

    expect((float) $we->prescribed_load)->toBe(37.5); // 35 + 2.5
});

// ── 7: cumplimiento de reps afecta la dirección ──

it('7: reps below the historical prescription block progress, even with rising load', function () {
    $contact = readyContactForProgressionIntegration();
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    $older = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
    $olderWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $older->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 30,
    ]);
    $olderLog = ExerciseLog::factory()->create(['workout_exercise_id' => $olderWe->id, 'rpe' => 5, 'logged_at' => now()->subDays(2)]);
    ExerciseSet::factory()->create(['exercise_log_id' => $olderLog->id, 'actual_reps' => 10, 'actual_load' => 30]);

    $recent = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    $recentWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $recent->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 35,
    ]);
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $recentWe->id, 'rpe' => 5, 'logged_at' => now()->subDay()]);
    // Carga sube (35 > 30) pero las reps quedan por debajo de lo prescrito.
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 6, 'actual_load' => 35]);

    $session = progressionIntegrationEngine()->decideNextSession($contact);
    $we = $session->workoutExercises->firstWhere('exercise_id', $exercise->id);

    expect((float) $we->prescribed_load)->toBe(35.0); // maintain, no progress
});

// ── 8: insufficient_data no inventa progresión ──

it('8: a single execution with no usable signal yields insufficient_data, never a fabricated progression', function () {
    // goal fijado explícitamente: GOAL_DEFAULTS varía reps por objetivo, y
    // esta aserción depende del valor exacto de general_fitness.
    $contact = readyContactForProgressionIntegration(['goal' => \App\Training\Enums\TrainingGoal::GeneralFitness]);
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    $session1 = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $we1 = WorkoutExercise::factory()->create([
        'workout_session_id' => $session1->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_reps' => null, 'prescribed_load' => null, 'prescribed_sets' => null,
    ]);
    ExerciseLog::factory()->create(['workout_exercise_id' => $we1->id, 'rpe' => null]);
    ExerciseSet::factory()->create(['exercise_log_id' => $we1->fresh()->exerciseLog->id, 'actual_reps' => null, 'actual_load' => null]);

    $session = progressionIntegrationEngine()->decideNextSession($contact);
    $we = $session->workoutExercises->firstWhere('exercise_id', $exercise->id);

    expect($we->prescribed_sets)->toBe(3);
    expect($we->prescribed_reps)->toBe(10);
    expect($we->prescribed_load)->toBeNull();
});

// ── 9: un solo contexto por generación ──

it('9: a single generation builds TrainingHistoryContext exactly once, regardless of exercises prescribed', function () {
    $contact = readyContactForProgressionIntegration();
    Exercise::factory()->create(['muscle_group' => 'chest']);
    Exercise::factory()->create(['muscle_group' => 'legs']);
    Exercise::factory()->create(['muscle_group' => 'back']);

    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);
    $realContext = (new TrainingHistoryContextProvider($safetyResolver))->build($contact->fresh());

    $spyProvider = Mockery::mock(TrainingHistoryContextProvider::class);
    $spyProvider->shouldReceive('build')->once()->andReturn($realContext);

    $engine = new TrainingEngine(new TrainingAccessGate, $safetyResolver, $spyProvider, new ProgressionEvaluator);
    $session = $engine->decideNextSession($contact->fresh());

    expect($session->workoutExercises)->toHaveCount(3);
    // Mockery::shouldReceive('build')->once() ya hace fallar el test si
    // build() se invoca 0 o 2+ veces.
});

// ── 10: sin consultas históricas duplicadas ──

it('10: query count is unaffected by how much history each prescribed exercise has', function () {
    // Escenario A: 3 candidatos elegibles, 3 prescritos, con historial
    // MÍNIMO — 1 ejecución completa (log + set) por ejercicio, para que
    // cada nivel de la carga anticipada (workoutExercises.exerciseLog.
    // exerciseSets) tenga al menos una fila real en ambos escenarios; de lo
    // contrario un nivel completamente vacío (0 filas) hace que Eloquent
    // omita la siguiente query anidada — una diferencia real pero ajena al
    // Bloque 8 (no escala con volumen, es un umbral vacío/no-vacío). Se le
    // da a cada uno al menos 1 WorkoutSession deliberadamente — un contacto
    // con CERO WorkoutSessions dispara además una consulta preexistente y
    // no relacionada de TrainingAccessGate (revisión de screening de salud
    // antes de la primera rutina, ver D048), que ensuciaría la comparación
    // si solo uno de los dos escenarios la disparara.
    $contactA = readyContactForProgressionIntegration();
    $keepIdsA = [];
    foreach (['chest', 'legs', 'back'] as $muscleGroup) {
        $exercise = Exercise::factory()->create(['muscle_group' => $muscleGroup]);
        $keepIdsA[] = $exercise->id;
        $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contactA->id]);
        $we = WorkoutExercise::factory()->create([
            'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        ]);
        $log = ExerciseLog::factory()->create(['workout_exercise_id' => $we->id, 'rpe' => 5]);
        ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 10, 'actual_load' => 30]);
    }
    // Neutraliza los ejercicios aleatorios que WorkoutExerciseFactory creó
    // como side-effect (ver factory) — deja solo los 3 candidatos reales.
    Exercise::query()->whereNotIn('id', $keepIdsA)->update(['is_active' => false]);

    DB::enableQueryLog();
    progressionIntegrationEngine()->decideNextSession($contactA);
    $queriesMinimalHistory = count(DB::getQueryLog());
    DB::flushQueryLog();

    // Escenario B: EL MISMO número de candidatos elegibles y prescritos
    // (3), pero cada uno con varias ejecuciones históricas RICAS — antes
    // del Bloque 8, esto disparaba una query adicional POR EJERCICIO dentro
    // de progressionFor(); ahora no debe cambiar nada, porque el contexto
    // ya viene completamente resuelto en memoria. Ambos escenarios tienen
    // >=1 WorkoutSession (mismo comportamiento de TrainingAccessGate) y el
    // mismo tamaño de catálogo elegible (mismo costo de isEligible()) — la
    // única variable real es la cantidad de historial.
    Exercise::query()->update(['is_active' => false]);

    $contactB = readyContactForProgressionIntegration();
    $chest = Exercise::factory()->create(['muscle_group' => 'chest']);
    $legs = Exercise::factory()->create(['muscle_group' => 'legs']);
    $back = Exercise::factory()->create(['muscle_group' => 'back']);

    foreach ([$chest, $legs, $back] as $exercise) {
        foreach ([2, 1] as $daysAgo) {
            $historySession = WorkoutSession::factory()->completed()->create(['contact_id' => $contactB->id, 'scheduled_at' => now()->subDays($daysAgo)]);
            $we = WorkoutExercise::factory()->create([
                'workout_session_id' => $historySession->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
                'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 30,
            ]);
            $log = ExerciseLog::factory()->create(['workout_exercise_id' => $we->id, 'rpe' => 5, 'logged_at' => now()->subDays($daysAgo)]);
            ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 10, 'actual_load' => 30]);
        }
    }
    // Neutraliza los ejercicios aleatorios que WorkoutExerciseFactory creó
    // como side-effect al fijar exercise_id explícitamente (ver factory).
    Exercise::query()->whereNotIn('id', [$chest->id, $legs->id, $back->id])->update(['is_active' => false]);

    DB::flushQueryLog();
    progressionIntegrationEngine()->decideNextSession($contactB);
    $queriesWithHistory = count(DB::getQueryLog());
    DB::flushQueryLog();

    expect($queriesWithHistory)->toBe($queriesMinimalHistory);
});

// ── 11: TrainingEngine sigue siendo la autoridad de prescripción numérica ──

it('11: TrainingEngine remains the sole owner of GOAL_DEFAULTS and the numeric increments', function () {
    $engineSource = file_get_contents(app_path('Training/Engine/TrainingEngine.php'));
    expect($engineSource)->toContain('GOAL_DEFAULTS');
    expect($engineSource)->toContain('+ 2.5');
    expect($engineSource)->toContain('+ 10');

    $evaluatorSource = file_get_contents(app_path('Training/Support/ProgressionEvaluator.php'));
    expect($evaluatorSource)->not->toContain('GOAL_DEFAULTS');
    expect($evaluatorSource)->not->toContain('prescribed_load');
    expect($evaluatorSource)->not->toContain('prescribed_reps =');
});

// ── 12: el evaluator sigue siendo la única autoridad de dirección ──

it('12: the direction computed by ProgressionEvaluator is unaffected by how TrainingEngine consumes it', function () {
    $contact = readyContactForProgressionIntegration();
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    $older = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
    $olderWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $older->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 30,
    ]);
    $olderLog = ExerciseLog::factory()->create(['workout_exercise_id' => $olderWe->id, 'rpe' => 5, 'logged_at' => now()->subDays(2)]);
    ExerciseSet::factory()->create(['exercise_log_id' => $olderLog->id, 'actual_reps' => 10, 'actual_load' => 30]);

    $recent = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    $recentWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $recent->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 35,
    ]);
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $recentWe->id, 'rpe' => 5, 'logged_at' => now()->subDay()]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 10, 'actual_load' => 35]);

    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);
    $context = (new TrainingHistoryContextProvider($safetyResolver))->build($contact->fresh());
    $directEvaluation = (new ProgressionEvaluator)->evaluate($context, $exercise->id, \App\Training\Enums\TrackingType::RepsAndLoad);

    progressionIntegrationEngine()->decideNextSession($contact);

    expect($directEvaluation->decision)->toBe(\App\Training\Enums\ProgressionDecision::Progress);
});

// ── 16: snapshots nuevos siguen siendo correctos ──

it('16: new snapshots never contain any ProgressionEvaluation-derived data', function () {
    $contact = readyContactForProgressionIntegration();
    Exercise::factory()->create(['muscle_group' => 'chest']);

    $session = progressionIntegrationEngine()->decideNextSession($contact);
    $we = $session->workoutExercises->first();

    expect($session->prescription_context_snapshot)->not->toHaveKey('reason_codes');
    expect($session->prescription_context_snapshot)->not->toHaveKey('decision');
    expect($session->prescription_context_snapshot)->not->toHaveKey('metrics');
    expect($we->exercise_snapshot)->not->toHaveKey('reason_codes');
    expect($we->exercise_snapshot)->not->toHaveKey('decision');
});

// ── 17: ejercicio histórico eliminado no rompe la generación ──

it('17: a deleted historical exercise does not break generation of prescriptions for other exercises', function () {
    $contact = readyContactForProgressionIntegration();

    $deletedExercise = Exercise::factory()->create(['muscle_group' => 'chest']);
    $pastSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $we = WorkoutExercise::factory()->create([
        'workout_session_id' => $pastSession->id, 'exercise_id' => $deletedExercise->id, 'exercise_snapshot' => $deletedExercise->toSnapshot(),
    ]);
    ExerciseLog::factory()->create(['workout_exercise_id' => $we->id, 'rpe' => 5]);
    $deletedExercise->delete();

    $liveExercise = Exercise::factory()->create(['muscle_group' => 'legs']);

    // WorkoutExerciseFactory::definition() crea su propio Exercise
    // aleatorio como side-effect incluso cuando se sobrescribe exercise_id
    // (ver factory) — se neutraliza para que el único candidato elegible
    // sea $liveExercise.
    Exercise::query()->where('id', '!=', $liveExercise->id)->update(['is_active' => false]);

    $session = progressionIntegrationEngine()->decideNextSession($contact);

    expect($session->workoutExercises)->toHaveCount(1);
    expect($session->workoutExercises->first()->exercise_id)->toBe($liveExercise->id);
});

// ── 18: no se persiste ProgressionEvaluation ──

it('18: no migration or table exists for ProgressionEvaluation', function () {
    foreach (glob(database_path('migrations/*.php')) as $file) {
        expect(file_get_contents($file))->not->toContain('progression_evaluations');
    }
});

// ── 19: determinismo ──

it('19: two contacts with identical history produce identical numeric prescriptions', function () {
    $build = function () {
        // goal fijado + catálogo neutralizado antes de cada build(): el
        // catálogo de Exercise es global (no scoped por contacto), y
        // WorkoutExerciseFactory crea un Exercise aleatorio como
        // side-effect propio — sin esto, la segunda llamada podría ver
        // candidatos de la primera y romper la comparación 1:1.
        Exercise::query()->update(['is_active' => false]);

        $contact = readyContactForProgressionIntegration(['goal' => \App\Training\Enums\TrainingGoal::GeneralFitness]);
        $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

        $older = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
        $olderWe = WorkoutExercise::factory()->create([
            'workout_session_id' => $older->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
            'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 30,
        ]);
        $olderLog = ExerciseLog::factory()->create(['workout_exercise_id' => $olderWe->id, 'rpe' => 5, 'logged_at' => now()->subDays(2)]);
        ExerciseSet::factory()->create(['exercise_log_id' => $olderLog->id, 'actual_reps' => 10, 'actual_load' => 30]);

        $recent = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
        $recentWe = WorkoutExercise::factory()->create([
            'workout_session_id' => $recent->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
            'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 35,
        ]);
        $log = ExerciseLog::factory()->create(['workout_exercise_id' => $recentWe->id, 'rpe' => 5, 'logged_at' => now()->subDay()]);
        ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 10, 'actual_load' => 35]);

        // Neutraliza también el Exercise aleatorio que las 2 llamadas a
        // WorkoutExercise::factory() de arriba acaban de crear, para que
        // la selección de esta generación solo vea $exercise.
        Exercise::query()->where('id', '!=', $exercise->id)->update(['is_active' => false]);

        return progressionIntegrationEngine()->decideNextSession($contact)->workoutExercises->firstWhere('exercise_id', $exercise->id);
    };

    $first = $build();
    $second = $build();

    expect((float) $first->prescribed_load)->toBe((float) $second->prescribed_load);
    expect($first->prescribed_reps)->toBe($second->prescribed_reps);
});

// ── 20/21: sin IA, sin WhatsApp ──

it('20/21: the numeric prescription method never references AI or WhatsApp', function () {
    $source = file_get_contents(app_path('Training/Engine/TrainingEngine.php'));

    expect($source)->not->toContain('AiServiceInterface');
    expect($source)->not->toContain('AIServiceFactory');
    // Nota: el docblock de la clase menciona "WhatsApp" para ACLARAR que no
    // lo conoce ("No conoce WhatsApp, LLM, ni Handlers") — se busca uso de
    // código real (import/llamada), no la mera mención en un comentario.
    expect($source)->not->toContain('use App\Services\WhatsApp');
    expect($source)->not->toContain('sendMessage(');
});

// ── Corrección: un Skipped reciente nunca sustituye al último Performed ──
// (mostRecentEntryFor() debe usar la MISMA E que ProgressionEvaluator, D050)

it('22: a Skipped execution more recent than the last Performed one is never the carry-forward source', function () {
    $contact = readyContactForProgressionIntegration(['goal' => \App\Training\Enums\TrainingGoal::GeneralFitness]);
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    $performedSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDays(2)]);
    $performedWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $performedSession->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 30,
    ]);
    $performedLog = ExerciseLog::factory()->create(['workout_exercise_id' => $performedWe->id, 'rpe' => 5, 'logged_at' => now()->subDays(2)]);
    ExerciseSet::factory()->create(['exercise_log_id' => $performedLog->id, 'actual_reps' => 10, 'actual_load' => 30]);

    // Skipped MÁS RECIENTE que el Performed, con datos deliberadamente
    // distintos (9/99/999) para detectar cualquier fuga hacia la
    // prescripción numérica.
    $skippedSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id, 'scheduled_at' => now()->subDay()]);
    $skippedWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $skippedSession->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 9, 'prescribed_reps' => 99, 'prescribed_load' => 999,
    ]);
    ExerciseLog::factory()->create(['workout_exercise_id' => $skippedWe->id, 'skip_reason' => 'cant_do', 'note' => 'No realizado.']);
    // Sin ExerciseSet -> outcome Skipped (log existe, cero sets).

    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);
    $context = (new TrainingHistoryContextProvider($safetyResolver))->build($contact->fresh());
    $evaluation = (new ProgressionEvaluator)->evaluate($context, $exercise->id, \App\Training\Enums\TrackingType::RepsAndLoad);

    // (1) El evaluator usa E = el Performed, no el Skipped más reciente.
    expect($evaluation->metrics->executionsConsidered)->toBe(1);
    expect($evaluation->metrics->lastIntensity)->toBe(30.0);
    // (6) El Skipped sigue siendo informativo, sin alterar la decisión.
    expect($evaluation->reasonCodes)->toContain('most_recent_execution_skipped');

    $session = progressionIntegrationEngine()->decideNextSession($contact);
    $we = $session->workoutExercises->firstWhere('exercise_id', $exercise->id);

    // numericPrescriptionFor() debe usar la MISMA E que el evaluator —
    // nunca los valores del Skipped (9/99/999).
    expect($we->prescribed_sets)->toBe(1); // (4) del Performed, no 9
    expect($we->prescribed_reps)->toBe(10); // (3) del Performed, no 99
    expect((float) $we->prescribed_load)->toBe(30.0); // (2) maintain: sin incremento, del Performed, no 999
});

it('23: when only a Skipped execution exists (no Performed at all), insufficient_data still yields GOAL_DEFAULTS, never the Skipped prescription', function () {
    $contact = readyContactForProgressionIntegration(['goal' => \App\Training\Enums\TrainingGoal::GeneralFitness]);
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest']);

    $skippedSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $skippedWe = WorkoutExercise::factory()->create([
        'workout_session_id' => $skippedSession->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 9, 'prescribed_reps' => 99, 'prescribed_load' => 999,
    ]);
    ExerciseLog::factory()->create(['workout_exercise_id' => $skippedWe->id, 'skip_reason' => 'no_time']);

    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);
    $context = (new TrainingHistoryContextProvider($safetyResolver))->build($contact->fresh());
    $evaluation = (new ProgressionEvaluator)->evaluate($context, $exercise->id, \App\Training\Enums\TrackingType::RepsAndLoad);

    // (5) Sin ningún Performed: insufficient_data, nunca se usa el Skipped.
    expect($evaluation->decision)->toBe(\App\Training\Enums\ProgressionDecision::InsufficientData);
    expect($evaluation->reasonCodes)->toContain('no_history');

    $session = progressionIntegrationEngine()->decideNextSession($contact);
    $we = $session->workoutExercises->firstWhere('exercise_id', $exercise->id);

    expect($we->prescribed_sets)->toBe(3); // GOAL_DEFAULTS[general_fitness], no 9
    expect($we->prescribed_reps)->toBe(10); // GOAL_DEFAULTS, no 99
    expect($we->prescribed_load)->toBeNull(); // nunca 999
});
