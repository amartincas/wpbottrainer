<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\DurationEstimator;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrainingHistoryContextProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Hito — Exercise Variety & Selection (MVP). Reemplaza la señal binaria
 * anterior (ANTI_REPETITION_LOOKBACK_SESSIONS/recentlyUsedExerciseIds) por
 * `TrainingEngine::varietyScore()` — un score continuo de exposición
 * reciente sobre `$recentSessions` (posición 0-4, RECENT_SESSIONS_LOOKBACK
 * sin cambios), usado exclusivamente como desempate DENTRO de
 * `sortCandidates()`, después de `difficultyMatchRank`.
 *
 * Nombres de helpers con prefijo `variety*` deliberado — evita colisión de
 * funciones top-level de Pest con `makeReadyContact()`/`trainingEngine()`
 * ya definidos en TrainingEngineTest.php (mismo proceso de test run).
 */
function varietyTrainingEngine(): TrainingEngine
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

function varietyReadyContact(array $profileOverrides = []): Contact
{
    $contact = Contact::factory()->create();

    TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'split_type' => SplitType::FullBody,
        'goal' => TrainingGoal::GeneralFitness, // 30min Tenant default -> N=3
        'experience_level' => ExperienceLevel::Beginner,
    ], $profileOverrides));

    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

/**
 * Invoca `TrainingEngine::varietyScore()` (privado) vía reflection — mismo
 * patrón ya usado en TrainingAccessAdministrationServiceTest.php para
 * probar métodos privados directamente.
 */
function varietyScoreOf(int $exerciseId, Collection $recentSessions): float
{
    $method = new ReflectionMethod(TrainingEngine::class, 'varietyScore');
    $method->setAccessible(true);

    return $method->invoke(varietyTrainingEngine(), $exerciseId, $recentSessions);
}

/**
 * Sesión pasada real (Completed), con los exercise_id indicados, ya con
 * `workoutExercises` cargado — exactamente la forma de `$recentSessions`
 * que `decideNextSession()` construye.
 */
function pastSession(Contact $contact, $scheduledAt, array $exerciseIds): WorkoutSession
{
    $session = WorkoutSession::factory()->create([
        'contact_id' => $contact->id,
        'status' => WorkoutSessionStatus::Completed,
        'scheduled_at' => $scheduledAt,
        'completed_at' => $scheduledAt,
    ]);

    foreach ($exerciseIds as $index => $exerciseId) {
        $exercise = Exercise::find($exerciseId);
        WorkoutExercise::factory()->create([
            'workout_session_id' => $session->id,
            'order' => $index + 1,
            'exercise_id' => $exerciseId,
            'exercise_snapshot' => $exercise->toSnapshot(),
        ]);
    }

    return $session->load('workoutExercises');
}

/**
 * `WorkoutExerciseFactory::definition()` crea un `Exercise::factory()->create()`
 * interno como efecto secundario en CADA llamada, incluso cuando
 * `exercise_id` se sobrescribe explícitamente (quirk ya documentado en este
 * proyecto) — con `muscle_group`/`difficulty_level` aleatorios de
 * `ExerciseFactory`, esos ejercicios "fantasma" pueden colar como
 * candidatos rank=0 elegibles inesperados en los tests de integración de
 * este archivo. Se desactivan explícitamente (is_active=false) todos los
 * ejercicios que NO forman parte del catálogo controlado de cada test,
 * justo antes de invocar decideNextSession() — nunca se toca la factory
 * ni TrainingEngine::isEligible(), que ya filtra por is_active=true.
 */
function deactivateStrayExercises(array $keepIds): void
{
    Exercise::whereNotIn('id', $keepIds)->update(['is_active' => false]);
}

// ─────────────────────────────────────────────────────────────────────────
// 1-6: varietyScore() — pruebas unitarias directas (reflection)
// ─────────────────────────────────────────────────────────────────────────

it('1: score 0 para un ejercicio ausente de las 5 sesiones visibles', function () {
    $contact = varietyReadyContact();
    $filler = Exercise::factory()->create();
    $absent = Exercise::factory()->create();

    $recentSessions = collect([
        pastSession($contact, now()->subDays(1), [$filler->id]),
        pastSession($contact, now()->subDays(2), [$filler->id]),
        pastSession($contact, now()->subDays(3), [$filler->id]),
        pastSession($contact, now()->subDays(4), [$filler->id]),
        pastSession($contact, now()->subDays(5), [$filler->id]),
    ]);

    expect(varietyScoreOf($absent->id, $recentSessions))->toBe(0.0);
});

it('2: score 1 (VARIETY_DECAY^0) cuando el ejercicio aparece únicamente en la sesión más reciente', function () {
    $contact = varietyReadyContact();
    $target = Exercise::factory()->create();
    $filler = Exercise::factory()->create();

    $recentSessions = collect([
        pastSession($contact, now()->subDays(1), [$target->id]), // posición 0
        pastSession($contact, now()->subDays(2), [$filler->id]),
        pastSession($contact, now()->subDays(3), [$filler->id]),
        pastSession($contact, now()->subDays(4), [$filler->id]),
        pastSession($contact, now()->subDays(5), [$filler->id]),
    ]);

    expect(varietyScoreOf($target->id, $recentSessions))->toBe(1.0);
});

it('3: score 0.6^4 = 0.1296 cuando el ejercicio aparece únicamente en la quinta sesión (posición 4)', function () {
    $contact = varietyReadyContact();
    $target = Exercise::factory()->create();
    $filler = Exercise::factory()->create();

    $recentSessions = collect([
        pastSession($contact, now()->subDays(1), [$filler->id]),
        pastSession($contact, now()->subDays(2), [$filler->id]),
        pastSession($contact, now()->subDays(3), [$filler->id]),
        pastSession($contact, now()->subDays(4), [$filler->id]),
        pastSession($contact, now()->subDays(5), [$target->id]), // posición 4
    ]);

    expect(varietyScoreOf($target->id, $recentSessions))->toBe(0.1296);
});

it('4: apariciones múltiples se suman — posiciones 0 y 2 -> 1 + 0.36 = 1.36', function () {
    $contact = varietyReadyContact();
    $target = Exercise::factory()->create();
    $filler = Exercise::factory()->create();

    $recentSessions = collect([
        pastSession($contact, now()->subDays(1), [$target->id]), // posición 0
        pastSession($contact, now()->subDays(2), [$filler->id]),
        pastSession($contact, now()->subDays(3), [$target->id]), // posición 2
        pastSession($contact, now()->subDays(4), [$filler->id]),
        pastSession($contact, now()->subDays(5), [$filler->id]),
    ]);

    expect(varietyScoreOf($target->id, $recentSessions))->toBe(1.36);
});

it('5: una sesión aporta como máximo UN término, aunque el mismo exercise_id aparezca dos veces dentro de ella', function () {
    $contact = varietyReadyContact();
    $target = Exercise::factory()->create();
    $filler = Exercise::factory()->create();

    // La sesión más reciente (posición 0) tiene DOS filas WorkoutExercise
    // con el mismo exercise_id — el score debe seguir siendo 1.0, nunca 2.0.
    $recentSessions = collect([
        pastSession($contact, now()->subDays(1), [$target->id, $target->id]),
        pastSession($contact, now()->subDays(2), [$filler->id]),
        pastSession($contact, now()->subDays(3), [$filler->id]),
        pastSession($contact, now()->subDays(4), [$filler->id]),
        pastSession($contact, now()->subDays(5), [$filler->id]),
    ]);

    expect(varietyScoreOf($target->id, $recentSessions))->toBe(1.0);
});

it('6: determinismo — misma entrada evaluada 3 veces produce exactamente el mismo score', function () {
    $contact = varietyReadyContact();
    $target = Exercise::factory()->create();
    $filler = Exercise::factory()->create();

    $recentSessions = collect([
        pastSession($contact, now()->subDays(1), [$target->id]),
        pastSession($contact, now()->subDays(2), [$filler->id]),
        pastSession($contact, now()->subDays(3), [$target->id]),
        pastSession($contact, now()->subDays(4), [$filler->id]),
        pastSession($contact, now()->subDays(5), [$filler->id]),
    ]);

    $first = varietyScoreOf($target->id, $recentSessions);
    $second = varietyScoreOf($target->id, $recentSessions);
    $third = varietyScoreOf($target->id, $recentSessions);

    expect($first)->toBe(1.36);
    expect($second)->toBe($first);
    expect($third)->toBe($first);
});

// ─────────────────────────────────────────────────────────────────────────
// 7-10: integración vía decideNextSession() — prioridades y conservación de N
// ─────────────────────────────────────────────────────────────────────────

it('7: la variedad NUNCA supera difficultyMatchRank — un candidato rank=0 muy repetido gana a uno rank=1 nunca usado', function () {
    $contact = varietyReadyContact(['experience_level' => ExperienceLevel::Beginner]);

    $matchA = Exercise::factory()->create(['muscle_group' => 'chest', 'difficulty_level' => 'beginner']);
    $matchB = Exercise::factory()->create(['muscle_group' => 'chest', 'difficulty_level' => 'beginner']);
    $matchC = Exercise::factory()->create(['muscle_group' => 'chest', 'difficulty_level' => 'beginner']);
    // Nunca usado (varietyScore=0, "el más fresco posible") pero de peor
    // nivel — NUNCA debe ganarle a los 3 anteriores, por muy repetidos que estén.
    $mismatchButFresh = Exercise::factory()->create(['muscle_group' => 'chest', 'difficulty_level' => 'intermediate']);

    // matchA/B/C presentes en las 5 sesiones más recientes -> varietyScore máximo.
    for ($i = 1; $i <= 5; $i++) {
        pastSession($contact, now()->subDays($i), [$matchA->id, $matchB->id, $matchC->id]);
    }

    deactivateStrayExercises([$matchA->id, $matchB->id, $matchC->id, $mismatchButFresh->id]);

    $session = varietyTrainingEngine()->decideNextSession($contact->fresh());
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->toEqualCanonicalizing([$matchA->id, $matchB->id, $matchC->id]);
    expect($selectedIds)->not->toContain($mismatchButFresh->id);
});

it('8: la variedad NUNCA supera el focus tier — un candidato del foco declarado, aunque muy repetido, gana a uno general nunca usado', function () {
    $contact = varietyReadyContact(['primary_focus' => ['chest']]);

    $focusExercise = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => 'chest', 'difficulty_level' => 'beginner']);
    // Nunca usado, tier general (muscle_group distinto de 'chest') —
    // varietyScore=0, pero pertenece a un tier inferior al de foco.
    $generalFreshA = Exercise::factory()->create(['muscle_group' => 'legs', 'difficulty_level' => 'beginner']);
    $generalFreshB = Exercise::factory()->create(['muscle_group' => 'legs', 'difficulty_level' => 'beginner']);

    // El candidato de foco aparece en las 5 sesiones más recientes -> varietyScore máximo.
    for ($i = 1; $i <= 5; $i++) {
        pastSession($contact, now()->subDays($i), [$focusExercise->id]);
    }

    deactivateStrayExercises([$focusExercise->id, $generalFreshA->id, $generalFreshB->id]);

    $session = varietyTrainingEngine()->decideNextSession($contact->fresh());
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    // El único candidato de foco declarado debe estar seleccionado —
    // el orden de concatenación de tiers (sin cambios) lo garantiza,
    // sin importar que sea el "peor" por variedad de todo el catálogo.
    expect($selectedIds)->toContain($focusExercise->id);
});

it('9: la variedad no altera N — la cantidad seleccionada sigue siendo exactamente la de exercisesForTargetDuration()', function () {
    $contact = varietyReadyContact(); // general_fitness + 30min Tenant default -> N=3

    // 6 candidatos elegibles (más que N=3), con distinta exposición reciente,
    // para que el reordenamiento por variedad realmente participe.
    $exercises = Exercise::factory()->count(6)->create(['muscle_group' => 'chest', 'difficulty_level' => 'beginner']);
    pastSession($contact, now()->subDay(), [$exercises[0]->id, $exercises[1]->id]);

    deactivateStrayExercises($exercises->pluck('id')->all());

    $session = varietyTrainingEngine()->decideNextSession($contact->fresh());

    expect($session->workoutExercises)->toHaveCount(3);
});

it('10: catálogo insuficiente — con menos elegibles que N, se seleccionan todos los disponibles y se registra TRAINING_DURATION_TARGET_UNREACHABLE', function () {
    Log::spy();

    $contact = varietyReadyContact();
    $contact->tenant()->update(['target_session_duration_minutes' => 60]); // general_fitness a 60min -> N=7

    // Solo 4 elegibles, con exposición reciente variada.
    $exercises = Exercise::factory()->count(4)->create(['muscle_group' => 'chest', 'difficulty_level' => 'beginner']);
    pastSession($contact, now()->subDay(), [$exercises[0]->id]);

    deactivateStrayExercises($exercises->pluck('id')->all());

    $session = varietyTrainingEngine()->decideNextSession($contact->fresh());

    expect($session->workoutExercises)->toHaveCount(4);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message) => $message === 'TRAINING_DURATION_TARGET_UNREACHABLE')
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────
// 11: reproducción de la política binaria ANTERIOR — referencia de
//     regresión. Implementación LOCAL DE TEST únicamente (nunca en
//     producción) — TrainingEngine.php ya NO contiene este mecanismo.
// ─────────────────────────────────────────────────────────────────────────

it('11: la política binaria anterior (referencia, solo en el test) produce el ciclo A-B-C-A-B-C observado en producción', function () {
    // Simulación PURA en PHP (sin BD, sin TrainingEngine) — reimplementa
    // ÚNICAMENTE para este test la señal binaria retirada
    // (ANTI_REPETITION_LOOKBACK_SESSIONS=2, boolean "usado o no") sobre un
    // catálogo controlado de 16 candidatos rank=0 (mismo tamaño verificado
    // en el catálogo real elegible). Documenta el comportamiento previo
    // como referencia — no ejercita ningún código de producción.
    $catalogIds = range(1, 16); // ids ascendentes, como el desempate final real
    $exercisesPerSession = 3;
    $lookback = 2;

    $history = [];
    for ($s = 0; $s < 12; $s++) {
        $recentIds = [];
        foreach (array_slice($history, -$lookback) as $sess) {
            foreach ($sess as $id) {
                $recentIds[$id] = true;
            }
        }

        $sorted = $catalogIds;
        usort($sorted, function ($a, $b) use ($recentIds) {
            $repeatA = isset($recentIds[$a]) ? 1 : 0;
            $repeatB = isset($recentIds[$b]) ? 1 : 0;
            if ($repeatA !== $repeatB) {
                return $repeatA <=> $repeatB;
            }

            return $a <=> $b;
        });

        $history[] = array_slice($sorted, 0, $exercisesPerSession);
    }

    expect($history[0])->toBe([1, 2, 3]);
    expect($history[1])->toBe([4, 5, 6]);
    expect($history[2])->toBe([7, 8, 9]);
    expect($history[3])->toBe([1, 2, 3]); // el ciclo se cierra en la 4ta sesión
    expect($history[4])->toBe([4, 5, 6]);
    expect($history[5])->toBe([7, 8, 9]);
    // Se repite exactamente cada 3 sesiones durante las 12 simuladas.
    for ($s = 0; $s < 12; $s++) {
        expect($history[$s])->toBe($history[$s % 3]);
    }
    // Solo 9 de los 16 candidatos rank=0 llegan a usarse alguna vez.
    $usedIds = collect($history)->flatten()->unique()->sort()->values()->all();
    expect($usedIds)->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9]);
});

// ─────────────────────────────────────────────────────────────────────────
// 12: la nueva política, sobre el TrainingEngine REAL, durante 30 sesiones
//     sintéticas — sin ciclo, cobertura 16/16 del catálogo controlado.
// ─────────────────────────────────────────────────────────────────────────

it('12: con la nueva política de variedad, 30 sesiones reales generadas por TrainingEngine no reproducen ningún ciclo y cubren las 16 alternativas rank=0 sin violar difficulty/focus', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));

    $contact = varietyReadyContact();

    // Catálogo controlado: EXACTAMENTE 16 candidatos rank=0 (mismo tamaño
    // verificado en el catálogo real elegible del hito de diagnóstico) +
    // 1 decoy rank=1 que nunca debería ganar, sin importar cuán "fresco" esté.
    $rank0 = Exercise::factory()->count(16)->create(['muscle_group' => 'chest', 'difficulty_level' => 'beginner']);
    $decoy = Exercise::factory()->create(['muscle_group' => 'chest', 'difficulty_level' => 'intermediate']);

    $selectedPerSession = [];

    for ($s = 0; $s < 30; $s++) {
        $session = varietyTrainingEngine()->decideNextSession($contact->fresh());
        $ids = $session->workoutExercises->pluck('exercise_id')->sort()->values()->all();
        $selectedPerSession[] = $ids;

        // El decoy (peor difficultyMatchRank) nunca debe aparecer, sin
        // importar cuántas sesiones pasen ni cuán repetidos estén los otros 16.
        expect($ids)->not->toContain($decoy->id);

        $session->update(['status' => WorkoutSessionStatus::Completed, 'completed_at' => now()]);
        Carbon::setTestNow(Carbon::now()->addHours(6));
    }

    Carbon::setTestNow();

    // Sin ciclo periódico exacto en el tramo final (últimas 12 de las 30).
    $tail = array_slice($selectedPerSession, -12);
    $sigs = array_map(fn ($s) => implode(',', $s), $tail);
    for ($period = 1; $period <= 4; $period++) {
        $isCycle = true;
        for ($i = $period; $i < count($sigs); $i++) {
            if ($sigs[$i] !== $sigs[$i - $period]) {
                $isCycle = false;
                break;
            }
        }
        expect($isCycle)->toBeFalse("No debería existir un ciclo exacto de período {$period} en el tramo final.");
    }

    // Cobertura: las 16 alternativas rank=0 controladas llegan a usarse —
    // propiedad verificada de ESTE catálogo controlado, no una garantía
    // matemática universal del algoritmo para cualquier catálogo.
    $usedIds = collect($selectedPerSession)->flatten()->unique()->values();
    expect($usedIds->count())->toBe(16);
    expect($usedIds->sort()->values()->all())->toBe($rank0->pluck('id')->sort()->values()->all());
});
