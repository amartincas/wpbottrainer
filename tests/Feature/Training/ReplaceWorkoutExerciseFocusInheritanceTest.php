<?php

use App\Models\Exercise;
use App\Models\TrainingPreference;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\PreferenceStatus;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Support\RequestedFocusGroup;

/**
 * Hito C (fix contexto de foco en sustitución, hallazgo real de auditoría
 * E2E post-D6 — sesión #48/#50 de staging) — `ReplaceWorkoutExerciseService`
 * ahora hereda `requested_focus` de la sesión cuando el mensaje de
 * sustitución no pidió uno explícito. Reutiliza `makeReadyContact()`/
 * `trainingEngine()` (TrainingEngineTest.php), `selectReplacementSession()`/
 * `selectReplacementTarget()` (TrainingEngineSelectReplacementTest.php) y
 * `replaceWorkoutExerciseService()` (ReplaceWorkoutExerciseServiceTest.php)
 * — mismo criterio de reutilización de helpers ya establecido entre estos 3
 * archivos. Debe ejecutarse siempre junto a ellos.
 *
 * Prefijo "rfic" ("Replace Focus Inheritance Contact") en helpers propios,
 * mismo criterio anti-colisión que "sub"/"nwrw" en otros archivos.
 */
function rficSnapshotWithFocus(array $groups): array
{
    return [
        'schema_version' => 1,
        'requested_focus' => array_map(fn (array $g) => ['key' => $g[0], 'muscles' => $g[1]], $groups),
    ];
}

it('C-FOCUS-1: requested_focus=chest + no explicit message focus → replacement is chest (inherited)', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession([
        'contact_id' => $contact->id,
        'prescription_context_snapshot' => rficSnapshotWithFocus([['chest', ['chest']]]),
    ]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    $chestCandidate = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    Exercise::factory()->create(['muscle_group' => 'legs', 'primary_muscle' => MuscleFocus::Quads]); // distractor
    $target = selectReplacementTarget($session, $original, 2, WorkoutExercisePhase::Main);

    $outcome = replaceWorkoutExerciseService()->replace($target);

    expect($outcome->status)->toBe('replaced');
    expect($outcome->replacement->exercise_id)->toBe($chestCandidate->id);
});

it('C-FOCUS-2: requested_focus=chest + explicit message focus legs → replacement is legs (explicit wins)', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession([
        'contact_id' => $contact->id,
        'prescription_context_snapshot' => rficSnapshotWithFocus([['chest', ['chest']]]),
    ]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]); // distractor: sería elegido si se heredara
    $legsCandidate = Exercise::factory()->create(['muscle_group' => 'legs', 'primary_muscle' => MuscleFocus::Quads]);
    $target = selectReplacementTarget($session, $original, 2, WorkoutExercisePhase::Main);

    $explicitLegs = new RequestedFocusGroup('legs', [MuscleFocus::Quads->value]);
    $outcome = replaceWorkoutExerciseService()->replace($target, $explicitLegs);

    expect($outcome->status)->toBe('replaced');
    expect($outcome->replacement->exercise_id)->toBe($legsCandidate->id);
});

it('C-FOCUS-3: no requested_focus + no explicit message focus → autonomous behavior unchanged', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]); // sin prescription_context_snapshot
    $original = Exercise::factory()->create(['muscle_group' => 'back']);
    $candidate = Exercise::factory()->create(['muscle_group' => 'back']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);

    $outcome = replaceWorkoutExerciseService()->replace($target);

    expect($outcome->status)->toBe('replaced');
    expect($outcome->replacement->exercise_id)->toBe($candidate->id);
});

it('C-FOCUS-3b: requested_focus=[] (array vacío) + no explicit message focus → autonomous behavior unchanged', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession([
        'contact_id' => $contact->id,
        'prescription_context_snapshot' => ['schema_version' => 1, 'requested_focus' => []],
    ]);
    $original = Exercise::factory()->create(['muscle_group' => 'core']);
    $candidate = Exercise::factory()->create(['muscle_group' => 'core']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);

    $outcome = replaceWorkoutExerciseService()->replace($target);

    expect($outcome->status)->toBe('replaced');
    expect($outcome->replacement->exercise_id)->toBe($candidate->id);
});

it('C-FOCUS-4: requested_focus=chest+arms + no explicit message focus → FIRST group (chest) inherited, never a fusion', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession([
        'contact_id' => $contact->id,
        // Orden original de la petición preservado: chest primero.
        'prescription_context_snapshot' => rficSnapshotWithFocus([
            ['chest', ['chest']],
            ['arms', [MuscleFocus::Biceps->value, MuscleFocus::Triceps->value]],
        ]),
    ]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    $chestCandidate = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    Exercise::factory()->create(['muscle_group' => 'arms', 'primary_muscle' => MuscleFocus::Biceps]); // nunca debe ganar
    $target = selectReplacementTarget($session, $original, 2, WorkoutExercisePhase::Main);

    $outcome = replaceWorkoutExerciseService()->replace($target);

    expect($outcome->status)->toBe('replaced');
    expect($outcome->replacement->exercise_id)->toBe($chestCandidate->id);
});

it('C-FOCUS-5: requested_focus=chest + explicit message focus shoulders → shoulders (explicit wins, same as C-FOCUS-2)', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession([
        'contact_id' => $contact->id,
        'prescription_context_snapshot' => rficSnapshotWithFocus([['chest', ['chest']]]),
    ]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    $shouldersCandidate = Exercise::factory()->create(['muscle_group' => 'shoulders', 'primary_muscle' => MuscleFocus::Shoulders]);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);

    $explicitShoulders = new RequestedFocusGroup('shoulders', [MuscleFocus::Shoulders->value]);
    $outcome = replaceWorkoutExerciseService()->replace($target, $explicitShoulders);

    expect($outcome->status)->toBe('replaced');
    expect($outcome->replacement->exercise_id)->toBe($shouldersCandidate->id);
});

it('C-FOCUS-6: inherited focus never reintroduces an exercise already used elsewhere in the same session (superseded included)', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession([
        'contact_id' => $contact->id,
        'prescription_context_snapshot' => rficSnapshotWithFocus([['chest', ['chest']]]),
    ]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    $alreadyUsedChest = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    $onlyRemainingChest = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    $target = selectReplacementTarget($session, $original, 2, WorkoutExercisePhase::Main);
    // $alreadyUsedChest ya apareció en esta MISMA sesión (superseded incluido).
    $historical = selectReplacementTarget($session, $alreadyUsedChest, 3, WorkoutExercisePhase::Main);
    $historical->update(['superseded_by_id' => $target->id]); // marca histórica arbitraria, solo para poblar workout_session_id

    $outcome = replaceWorkoutExerciseService()->replace($target);

    expect($outcome->status)->toBe('replaced');
    expect($outcome->replacement->exercise_id)->toBe($onlyRemainingChest->id);
    expect($outcome->replacement->exercise_id)->not->toBe($alreadyUsedChest->id);
});

it('C-FOCUS-7: inherited focus never bypasses an active TrainingPreference exclusion', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession([
        'contact_id' => $contact->id,
        'prescription_context_snapshot' => rficSnapshotWithFocus([['chest', ['chest']]]),
    ]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    $excludedChest = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    TrainingPreference::create([
        'contact_id' => $contact->id,
        'dimension' => PreferenceDimension::Exercise,
        'exercise_id' => $excludedChest->id,
        'preference_key' => "exercise:{$excludedChest->id}",
        'status' => PreferenceStatus::Active,
        'original_text' => 'No me gusta.',
        'created_at' => now(),
    ]);
    $allowedChest = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);

    $outcome = replaceWorkoutExerciseService()->replace($target);

    expect($outcome->status)->toBe('replaced');
    expect($outcome->replacement->exercise_id)->toBe($allowedChest->id);
    expect($outcome->replacement->exercise_id)->not->toBe($excludedChest->id);
});
