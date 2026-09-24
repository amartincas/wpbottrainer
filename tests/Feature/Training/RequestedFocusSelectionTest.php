<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\DurationEstimator;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\RequestedFocusGroup;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrainingHistoryContextProvider;
use App\Training\Support\TrainingPreferenceResolver;
use Illuminate\Support\Facades\Log;

// Hito B1 (Requested Focus) — helpers propios de este archivo (prefijo
// "rf") para evitar colisión de funciones globales con TrainingEngineTest.php
// (makeReadyContact/trainingEngine), que ya existen en el mismo proceso Pest.

function rfReadyContact(array $profileOverrides = []): Contact
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

function rfEngine(): TrainingEngine
{
    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);

    return new TrainingEngine(
        new TrainingAccessGate,
        $safetyResolver,
        new TrainingHistoryContextProvider($safetyResolver, new TrainingPreferenceResolver),
        new ProgressionEvaluator,
        new DurationEstimator,
        new TrainingPreferenceResolver,
    );
}

/**
 * `count` ejercicios `beginner`, activos, con `primary_muscle` = $muscle,
 * sin equipo — deliberadamente uniformes para que difficultyMatchRank()/
 * varietyScore() nunca desempaten por accidente entre ellos (mismo criterio
 * que TrainingEngineTest.php: explícito, nunca aleatorio).
 */
function rfExercises(string $muscle, int $count, array $overrides = []): void
{
    for ($i = 0; $i < $count; $i++) {
        Exercise::factory()->create(array_merge([
            'muscle_group' => 'core', // deliberadamente fuera del vocabulario grueso usado por defaultFocus() para full_body, irrelevante a los tests de este archivo salvo que se pida explícitamente
            'primary_muscle' => MuscleFocus::from($muscle),
            'difficulty_level' => 'beginner',
            'equipment_needed' => [],
        ], $overrides));
    }
}

function coverageByKey(array $snapshot, string $key): ?array
{
    foreach ($snapshot['requested_focus_coverage'] as $entry) {
        if ($entry['key'] === $key) {
            return $entry;
        }
    }

    return null;
}

// ─────────────────────────────────────────────────────────────────────────
// Slots — tabla determinista (tests 13-18 del diseño aprobado)
// ─────────────────────────────────────────────────────────────────────────

it('13-18: slot allocation table — deterministic, key ASC, never mention order', function (int $targetMinutes, int $groupCount, array $expectedSlots) {
    // g1..g5, cada uno con un MuscleFocus real distinto y catálogo abundante
    // (4 c/u, nunca el cuello de botella de esta tabla) — el objetivo es
    // aislar puramente la matemática de reparto de slots.
    $muscles = ['chest', 'back', 'shoulders', 'abs', 'biceps'];
    $groups = [];

    for ($i = 0; $i < $groupCount; $i++) {
        $key = 'g'.($i + 1);
        rfExercises($muscles[$i], 4);
        $groups[] = new RequestedFocusGroup($key, [$muscles[$i]]);
    }

    $contact = rfReadyContact();
    $contact->tenant()->update(['target_session_duration_minutes' => $targetMinutes]);

    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);
    $coverage = $session->prescription_context_snapshot['requested_focus_coverage'];

    expect($coverage)->toHaveCount($groupCount);

    foreach ($expectedSlots as $index => $expected) {
        expect($coverage[$index]['key'])->toBe('g'.($index + 1));
        expect($coverage[$index]['slots_reserved'])->toBe($expected);
    }
})->with([
    '13: 2 ejercicios / 3 grupos -> 1,1,0' => [18, 3, [1, 1, 0]],
    '14: 3 ejercicios / 4 grupos -> 1,1,1,0' => [30, 4, [1, 1, 1, 0]],
    '15: 4 ejercicios / 5 grupos -> 1,1,1,1,0' => [39, 5, [1, 1, 1, 1, 0]],
    '16: 5 ejercicios / 2 grupos -> 3,2' => [45, 2, [3, 2]],
    '17: 6 ejercicios / 2 grupos -> 3,3' => [60, 2, [3, 3]],
    '18: 8 ejercicios / 3 grupos -> 3,3,2' => [78, 3, [3, 3, 2]],
]);

// ─────────────────────────────────────────────────────────────────────────
// Overlap — un ejercicio relevante para 2 grupos (tests 19-21)
// ─────────────────────────────────────────────────────────────────────────

it('19-21: an exercise relevant to two groups never occupies two reserved slots, but DOES count toward both coverages', function () {
    $contact = rfReadyContact();
    $contact->tenant()->update(['target_session_duration_minutes' => 18]); // N=2

    // B: primary=back -> relevante SOLO para el grupo "back". Creado ANTES
    // que A a propósito: el procesamiento de reservas es key ASC ("back" <
    // "chest"), y el desempate final de sortCandidates() es id ASC — B debe
    // tener el id más bajo para que el grupo "back" (procesado primero) lo
    // reclame a ÉL, dejando a A libre para "chest" (ver test 19-21 abajo:
    // demuestra que ninguno de los 2 grupos se queda sin cupo por la
    // colisión, cuando el catálogo realmente alcanza para ambos).
    $exerciseB = Exercise::factory()->create([
        'muscle_group' => 'core',
        'primary_muscle' => MuscleFocus::Back,
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);
    // A: primary=chest, secondary=back -> relevante para AMBOS grupos.
    $exerciseA = Exercise::factory()->create([
        'muscle_group' => 'core',
        'primary_muscle' => MuscleFocus::Chest,
        'secondary_muscles' => ['back'],
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);

    $groups = [
        new RequestedFocusGroup('chest', ['chest']),
        new RequestedFocusGroup('back', ['back']),
    ];

    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);

    // 19/21: 2 slots reservados (1+1), llenados por 2 ejercicios FÍSICAMENTE
    // DISTINTOS — nunca el mismo id repetido para "cumplir" los 2 grupos.
    $mainIds = $session->workoutExercises->pluck('exercise_id')->unique();
    expect($mainIds)->toHaveCount(2);
    expect($mainIds->all())->toEqualCanonicalizing([$exerciseA->id, $exerciseB->id]);

    $snapshot = $session->prescription_context_snapshot;
    $chestCoverage = coverageByKey($snapshot, 'chest');
    $backCoverage = coverageByKey($snapshot, 'back');

    expect($chestCoverage['slots_reserved'])->toBe(1);
    expect($chestCoverage['slots_filled'])->toBe(1);
    expect($backCoverage['slots_reserved'])->toBe(1);
    expect($backCoverage['slots_filled'])->toBe(1);

    // 20: coverage (conteo generoso) SÍ cuenta a A para ambos grupos —
    // "back" termina con coverage=2 (A por secondary_muscles + B por
    // primary_muscle) aunque solo tenía 1 slot reservado/llenado.
    expect($chestCoverage['coverage'])->toBe(1);
    expect($backCoverage['coverage'])->toBe(2);
    expect($chestCoverage['status'])->toBe('fulfilled');
    expect($backCoverage['status'])->toBe('fulfilled');
});

// ─────────────────────────────────────────────────────────────────────────
// Catálogo (tests 22-27)
// ─────────────────────────────────────────────────────────────────────────

it('22: all groups with sufficient candidates -> fulfilled, no reason', function () {
    rfExercises('chest', 3);
    rfExercises('back', 3);

    $contact = rfReadyContact();
    $contact->tenant()->update(['target_session_duration_minutes' => 18]); // N=2, G=2 -> 1 slot c/u, sobra catálogo

    $groups = [new RequestedFocusGroup('chest', ['chest']), new RequestedFocusGroup('back', ['back'])];
    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);
    $snapshot = $session->prescription_context_snapshot;

    foreach (['chest', 'back'] as $key) {
        $entry = coverageByKey($snapshot, $key);
        expect($entry['status'])->toBe('fulfilled');
        expect($entry['reason'])->toBeNull();
    }
});

it('23: a group with some but not enough candidates -> partial, reason=catalog, TRAINING_REQUESTED_FOCUS_PARTIAL logged', function () {
    Log::spy();

    rfExercises('chest', 1); // theoretical=3 (N=6,G=2), solo 1 disponible
    rfExercises('back', 5);

    $contact = rfReadyContact();
    $contact->tenant()->update(['target_session_duration_minutes' => 60]); // N=6

    $groups = [new RequestedFocusGroup('chest', ['chest']), new RequestedFocusGroup('back', ['back'])];
    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);
    $entry = coverageByKey($session->prescription_context_snapshot, 'chest');

    expect($entry['status'])->toBe('partial');
    expect($entry['reason'])->toBe('catalog');
    expect($entry['slots_reserved'])->toBe(1);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'TRAINING_REQUESTED_FOCUS_PARTIAL' && $context['group'] === 'chest')
        ->once();
});

it('24: a group with zero candidates in the whole active catalog -> unavailable/catalog, TRAINING_REQUESTED_FOCUS_UNAVAILABLE logged', function () {
    Log::spy();

    rfExercises('back', 3); // catálogo NUNCA tiene "biceps"

    $contact = rfReadyContact();
    $contact->tenant()->update(['target_session_duration_minutes' => 18]);

    $groups = [new RequestedFocusGroup('biceps', ['biceps']), new RequestedFocusGroup('back', ['back'])];
    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);
    $entry = coverageByKey($session->prescription_context_snapshot, 'biceps');

    expect($entry['status'])->toBe('unavailable');
    expect($entry['reason'])->toBe('catalog');
    expect($entry['slots_filled'])->toBe(0);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'TRAINING_REQUESTED_FOCUS_UNAVAILABLE' && $context['group'] === 'biceps' && $context['reason'] === 'catalog')
        ->once();
});

it('25: a group with candidates excluded entirely by equipment eligibility -> unavailable/safety_or_equipment', function () {
    Log::spy();

    // El catálogo SÍ tiene "shoulders", pero exige equipo que el perfil no declara.
    Exercise::factory()->create([
        'muscle_group' => 'core',
        'primary_muscle' => MuscleFocus::Shoulders,
        'difficulty_level' => 'beginner',
        'equipment_needed' => ['barbell'],
    ]);
    rfExercises('back', 3);

    $contact = rfReadyContact(['equipment_fully_equipped' => false, 'available_equipment' => []]);
    $contact->tenant()->update(['target_session_duration_minutes' => 18]);

    $groups = [new RequestedFocusGroup('shoulders', ['shoulders']), new RequestedFocusGroup('back', ['back'])];
    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);
    $entry = coverageByKey($session->prescription_context_snapshot, 'shoulders');

    expect($entry['status'])->toBe('unavailable');
    expect($entry['reason'])->toBe('safety_or_equipment');

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'TRAINING_REQUESTED_FOCUS_UNAVAILABLE' && $context['reason'] === 'safety_or_equipment')
        ->once();
});

it('26: requested focus never blocks WorkoutSession creation, even when every requested group is unavailable', function () {
    // Ningún ejercicio de "biceps"/"triceps" en el catálogo — pero SÍ hay
    // uno que cae en el generalTier autónomo (muscle_group='core', dentro
    // de la rotación full_body por defecto), así que la sesión igual se crea.
    Exercise::factory()->create([
        'muscle_group' => 'core',
        'primary_muscle' => null,
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);

    $contact = rfReadyContact();
    $contact->tenant()->update(['target_session_duration_minutes' => 18]);

    $groups = [new RequestedFocusGroup('biceps', ['biceps']), new RequestedFocusGroup('triceps', ['triceps'])];
    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);

    expect($session)->not->toBeNull();
    expect($session->workoutExercises->where('phase', WorkoutExercisePhase::Main))->toHaveCount(1);

    foreach (['biceps', 'triceps'] as $key) {
        expect(coverageByKey($session->prescription_context_snapshot, $key)['status'])->toBe('unavailable');
    }
});

it('27: requested focus never relaxes isEligible() — the equipment-blocked exercise from test 25 never appears in the session', function () {
    $blocked = Exercise::factory()->create([
        'muscle_group' => 'core',
        'primary_muscle' => MuscleFocus::Shoulders,
        'difficulty_level' => 'beginner',
        'equipment_needed' => ['barbell'],
    ]);
    rfExercises('back', 3);

    $contact = rfReadyContact(['equipment_fully_equipped' => false, 'available_equipment' => []]);
    $contact->tenant()->update(['target_session_duration_minutes' => 18]);

    $groups = [new RequestedFocusGroup('shoulders', ['shoulders']), new RequestedFocusGroup('back', ['back'])];
    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);

    expect($session->workoutExercises->pluck('exercise_id'))->not->toContain($blocked->id);
});

// ─────────────────────────────────────────────────────────────────────────
// G > N — session_too_short (tests 28-30)
// ─────────────────────────────────────────────────────────────────────────

it('28-30: more groups than exercises -> no fictitious slots, unfunded groups marked session_too_short', function () {
    $muscles = ['chest', 'back', 'shoulders'];
    $groups = [];
    foreach ($muscles as $i => $muscle) {
        rfExercises($muscle, 4);
        $groups[] = new RequestedFocusGroup('g'.($i + 1), [$muscle]);
    }

    $contact = rfReadyContact();
    $contact->tenant()->update(['target_session_duration_minutes' => 18]); // N=2, G=3

    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);
    $snapshot = $session->prescription_context_snapshot;

    expect($snapshot['requested_focus_coverage'][0]['slots_reserved'])->toBe(1);
    expect($snapshot['requested_focus_coverage'][1]['slots_reserved'])->toBe(1);
    expect($snapshot['requested_focus_coverage'][2]['slots_reserved'])->toBe(0);
    expect($snapshot['requested_focus_coverage'][2]['status'])->toBe('unavailable');
    expect($snapshot['requested_focus_coverage'][2]['reason'])->toBe('session_too_short');

    // Nunca un slot ficticio: exactamente N ejercicios de Main, nunca N+1.
    expect($session->workoutExercises->where('phase', WorkoutExercisePhase::Main))->toHaveCount(2);
});

// ─────────────────────────────────────────────────────────────────────────
// Integración con TrainingEngine (tests 31-38)
// ─────────────────────────────────────────────────────────────────────────

it('31: requestedFocus=null preserves EXACTLY the existing behavior — same result as calling without the second argument', function () {
    rfExercises('chest', 3);

    $contact1 = rfReadyContact(['primary_focus' => ['chest']]);
    $contact2 = rfReadyContact(['primary_focus' => ['chest']]);
    // Catálogo compartido: ambos contactos ven el mismo pool (misma tabla).

    $sessionA = rfEngine()->decideNextSession($contact1->fresh());
    $sessionB = rfEngine()->decideNextSession($contact2->fresh(), null);

    expect($sessionA->workoutExercises->count())->toBe($sessionB->workoutExercises->count());
    expect($sessionA->prescription_context_snapshot['requested_focus'])->toBe([]);
    expect($sessionB->prescription_context_snapshot['requested_focus'])->toBe([]);
    expect($sessionA->prescription_context_snapshot['requested_focus_coverage'])->toBe([]);
});

it('32: requestedFocus present triggers group-based selection, not the flat primary_focus/secondary_focus tiers', function () {
    rfExercises('biceps', 3);

    // El perfil declara primary_focus=chest (sin ningún candidato en el
    // catálogo), pero requestedFocus pide "biceps" — la sesión debe
    // reflejar la petición puntual, no el perfil persistente.
    $contact = rfReadyContact(['primary_focus' => ['chest']]);
    $contact->tenant()->update(['target_session_duration_minutes' => 18]);

    $groups = [new RequestedFocusGroup('biceps', ['biceps'])];
    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);

    expect($session->workoutExercises->where('phase', WorkoutExercisePhase::Main))->toHaveCount(2);
    expect(coverageByKey($session->prescription_context_snapshot, 'biceps')['status'])->toBe('fulfilled');
});

it('33-34-36: autonomousFocus is always computed and next_focus is derived from it, never from requestedFocus', function () {
    rfExercises('biceps', 3);

    // split_type=FullBody -> ROTATIONS['full_body'] tiene UNA sola entrada:
    // next_focus siempre será exactamente esa cadena, sin importar qué
    // requestedFocus se haya pedido — el valor esperado no depende de
    // ninguna lectura previa, es una constante conocida del propio motor.
    $contact = rfReadyContact(['split_type' => SplitType::FullBody]);

    $groups = [new RequestedFocusGroup('biceps', ['biceps'])];
    rfEngine()->decideNextSession($contact->fresh(), $groups);

    $profileAfter = $contact->trainingProfile()->first()->fresh();

    expect($profileAfter->next_focus)->toBe('arms,back,chest,core,legs,shoulders');
});

it('35: requestedFocus never modifies TrainingProfile.primary_focus/secondary_focus', function () {
    rfExercises('biceps', 3);

    $contact = rfReadyContact(['primary_focus' => ['chest'], 'secondary_focus' => ['back']]);

    $groups = [new RequestedFocusGroup('biceps', ['biceps'])];
    rfEngine()->decideNextSession($contact->fresh(), $groups);

    $profileAfter = $contact->trainingProfile()->first()->fresh();

    expect($profileAfter->primary_focus)->toBe(['chest']);
    expect($profileAfter->secondary_focus)->toBe(['back']);
});

it('37: the free pool prefers leftover candidates from the requested groups before falling back to the autonomous generalTier', function () {
    // 1 grupo (chest) con MÁS candidatos que su reserva teórica (sobra), y
    // un candidato del generalTier autónomo (muscle_group='chest', dentro
    // de la rotación full_body) también disponible — el sobrante del
    // propio grupo debe llenarse ANTES que recurrir al generalTier.
    rfExercises('chest', 3); // 3 candidatos de chest, teórico=1 (N=2,G=1) -> 2 sobran
    $generalOnly = Exercise::factory()->create([
        // Relevante SOLO por muscle_group (generalTier), nunca por
        // primary_muscle/secondary_muscles (grupo solicitado).
        'muscle_group' => 'chest',
        'primary_muscle' => null,
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);

    $contact = rfReadyContact();
    $contact->tenant()->update(['target_session_duration_minutes' => 18]); // N=2

    $groups = [new RequestedFocusGroup('chest', ['chest'])];
    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);

    expect($session->workoutExercises->pluck('exercise_id'))->not->toContain($generalOnly->id);
});

it('38: once the requested groups are exhausted, the free pool falls back to the autonomous generalTier', function () {
    rfExercises('chest', 1); // solo 1 candidato de chest en total, teórico=2 (N=2,G=1)
    $generalFallback = Exercise::factory()->create([
        'muscle_group' => 'chest',
        'primary_muscle' => null,
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);

    $contact = rfReadyContact();
    $contact->tenant()->update(['target_session_duration_minutes' => 18]); // N=2

    $groups = [new RequestedFocusGroup('chest', ['chest'])];
    $session = rfEngine()->decideNextSession($contact->fresh(), $groups);

    expect($session->workoutExercises->pluck('exercise_id'))->toContain($generalFallback->id);
    expect($session->workoutExercises->where('phase', WorkoutExercisePhase::Main))->toHaveCount(2);
});
