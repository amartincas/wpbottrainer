<?php

use App\Models\Exercise;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Support\ExerciseMessageFormatter;

/**
 * Hito 9.2 — el formateador nunca lee del `Exercise` en vivo ni de ningún
 * proveedor: crea el `WorkoutExercise` con un `exercise_snapshot` explícito
 * en cada test, exactamente como lo produce `Exercise::toSnapshot()` en
 * producción, para probar el contrato real, no un atajo.
 */
function makeWorkoutExerciseWithSnapshot(array $snapshotOverrides = [], array $prescriptionOverrides = [], array $exerciseOverrides = []): WorkoutExercise
{
    $exercise = Exercise::factory()->create($exerciseOverrides);
    $session = WorkoutSession::factory()->create();

    $snapshot = array_merge($exercise->toSnapshot(), $snapshotOverrides);

    return WorkoutExercise::factory()->create(array_merge([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $snapshot,
        'prescribed_sets' => 3,
        'prescribed_reps' => 10,
        'prescribed_load' => null,
        'prescribed_duration_seconds' => null,
    ], $prescriptionOverrides));
}

it('shows the exercise name and prescription with a numbered header', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(['name' => 'Flexiones']);

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 2);

    expect($text)->toContain('2. *Flexiones* — 3 series x 10 repeticiones');
});

it('shows a duration-based prescription for time-based exercises', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(
        ['name' => 'Plancha'],
        ['prescribed_sets' => 3, 'prescribed_reps' => null, 'prescribed_duration_seconds' => 30],
    );

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->toContain('3 series x 30 segundos');
});

it('shows instructions as technique bullets', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot([
        'instructions' => ['Manos a la anchura de los hombros', 'Cuerpo alineado'],
    ]);

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->toContain('📋 Técnica:');
    expect($text)->toContain('- Manos a la anchura de los hombros');
    expect($text)->toContain('- Cuerpo alineado');
});

it('shows important_points merged into the same technique section', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot([
        'instructions' => ['Baja controlando'],
        'important_points' => ['Mantén el core activado'],
    ]);

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->toContain('- Baja controlando');
    expect($text)->toContain('- Mantén el core activado');
});

it('caps the combined technique bullets to avoid an excessively long message', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot([
        'instructions' => ['Paso 1', 'Paso 2', 'Paso 3'],
        'important_points' => ['Punto 1', 'Punto 2', 'Punto 3'],
    ]);

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);
    $bulletCount = substr_count($text, "\n- ");

    expect($bulletCount)->toBeLessThanOrEqual(4);
});

it('shows breathing_cue only when it exists', function () {
    $withCue = makeWorkoutExerciseWithSnapshot(['breathing_cue' => 'Inhala al bajar, exhala al subir']);
    $withoutCue = makeWorkoutExerciseWithSnapshot(['breathing_cue' => null]);

    $formatter = new ExerciseMessageFormatter;

    expect($formatter->format($withCue, 1))->toContain('🫁 Respiración: Inhala al bajar, exhala al subir');
    expect($formatter->format($withoutCue, 1))->not->toContain('🫁 Respiración');
});

it('shows common_mistakes only when they exist, capped for brevity', function () {
    $withMistakes = makeWorkoutExerciseWithSnapshot(['common_mistakes' => ['Arquear la espalda', 'Bajar muy rápido', 'Un tercero']]);
    $withoutMistakes = makeWorkoutExerciseWithSnapshot(['common_mistakes' => null]);

    $formatter = new ExerciseMessageFormatter;
    $textWithMistakes = $formatter->format($withMistakes, 1);

    expect($textWithMistakes)->toContain('⚠️ Evita:');
    expect($textWithMistakes)->toContain('- Arquear la espalda');
    expect($textWithMistakes)->not->toContain('Un tercero'); // acotado a 2
    expect($formatter->format($withoutMistakes, 1))->not->toContain('⚠️ Evita');
});

it('never produces an empty section header when a field is null', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot([
        'instructions' => [],
        'important_points' => null,
        'common_mistakes' => null,
        'breathing_cue' => null,
    ]);

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->not->toContain('📋 Técnica:');
    expect($text)->not->toContain('🫁 Respiración');
    expect($text)->not->toContain('⚠️ Evita');
    // El nombre/prescripción y la referencia al video siguen presentes.
    expect($text)->toContain('🎥 Video a continuación');
});

it('always mentions the video, regardless of how much technique content exists', function () {
    $rich = makeWorkoutExerciseWithSnapshot(['instructions' => ['Paso 1'], 'breathing_cue' => 'Respira normal']);
    $bare = makeWorkoutExerciseWithSnapshot(['instructions' => [], 'important_points' => null, 'common_mistakes' => null, 'breathing_cue' => null]);

    $formatter = new ExerciseMessageFormatter;

    expect($formatter->format($rich, 1))->toContain('🎥 Video a continuación');
    expect($formatter->format($bare, 1))->toContain('🎥 Video a continuación');
});

// ── Ronda 2 (piloto real), Cambio 3: orientación de peso cuando no hay
// ninguna carga calculada todavía — nunca inventa un número. ───────────

it('adds a plain-language weight-selection instruction when prescribed_load is null for an exercise that needs load-bearing equipment', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(
        prescriptionOverrides: ['prescribed_load' => null, 'prescribed_duration_seconds' => null],
        exerciseOverrides: ['equipment_needed' => ['dumbbells']],
    );

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->toContain('Elige un peso');
    expect($text)->toContain('Cuéntame qué peso usaste');
    expect($text)->not->toContain('RPE'); // nunca jerga técnica de cara al usuario
});

it('never adds the weight-selection instruction for a bodyweight exercise (no equipment needed), even with prescribed_load null', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(
        prescriptionOverrides: ['prescribed_load' => null, 'prescribed_duration_seconds' => null],
        exerciseOverrides: ['equipment_needed' => []],
    );

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->not->toContain('Elige un peso');
});

it('never adds the weight-selection instruction once a real load has been prescribed', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(
        prescriptionOverrides: ['prescribed_load' => 40, 'prescribed_duration_seconds' => null],
        exerciseOverrides: ['equipment_needed' => ['dumbbells']],
    );

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->not->toContain('Elige un peso');
});

it('never adds the weight-selection instruction for a time-based exercise, regardless of equipment', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(
        prescriptionOverrides: ['prescribed_load' => null, 'prescribed_reps' => null, 'prescribed_duration_seconds' => 30],
        exerciseOverrides: ['equipment_needed' => ['dumbbells']],
    );

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->not->toContain('Elige un peso');
});

it('never assumes an exercise needs load when its live Exercise relation cannot be resolved', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(prescriptionOverrides: ['prescribed_load' => null, 'prescribed_duration_seconds' => null]);
    $workoutExercise->exercise()->delete();
    $workoutExercise->refresh();

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->not->toContain('Elige un peso');
});

// ── H16.2.2 — "músculos trabajados": lee exclusivamente del snapshot ya
// congelado (Exercise::toSnapshot() ya incluía primary_muscle/
// secondary_muscles desde Hito 8.4) — determinista, sin IA. ────────────

it('renders the real "Elevaciones de gemelos con mancuernas" card in the exact expected shape: nombre+prescripción -> músculos -> técnica', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(
        ['name' => 'Elevaciones de gemelos con mancuernas', 'instructions' => ['Sube el talón hasta arriba', 'Baja controlando']],
        exerciseOverrides: ['primary_muscle' => \App\Training\Enums\MuscleFocus::Calves, 'secondary_muscles' => []],
    );

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->toStartWith("1. *Elevaciones de gemelos con mancuernas* — 3 series x 10 repeticiones\n");
    expect($text)->toContain("🎯 Músculos trabajados:\nPrincipalmente: pantorrillas.");
    expect(strpos($text, 'Músculos trabajados'))->toBeLessThan(strpos($text, '📋 Técnica:'));
    // Sin explicación anatómica extensa: solo el nombre del músculo, nada
    // más que lo que el snapshot trae.
    expect(substr_count($text, "\n"))->toBeLessThan(15);
});

it('shows the primary and secondary muscles worked, grounded in the frozen snapshot', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(exerciseOverrides: [
        'primary_muscle' => \App\Training\Enums\MuscleFocus::Glutes,
        'secondary_muscles' => ['hamstrings'],
    ]);

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->toContain('🎯 Músculos trabajados:');
    expect($text)->toContain('Principalmente: glúteos.');
    expect($text)->toContain('También: isquiotibiales.');
});

it('joins 2+ secondary muscles naturally with "y", never a raw comma-only list nor the raw enum values', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(exerciseOverrides: [
        'primary_muscle' => \App\Training\Enums\MuscleFocus::Glutes,
        'secondary_muscles' => ['hamstrings', 'calves'],
    ]);

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->toContain('También: isquiotibiales y pantorrillas.');
    expect($text)->not->toContain('hamstrings');
    expect($text)->not->toContain('calves');
});

it('shows only the primary muscle when there are no secondary muscles, without an empty "También" line', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(exerciseOverrides: [
        'primary_muscle' => \App\Training\Enums\MuscleFocus::Chest,
        'secondary_muscles' => [],
    ]);

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->toContain('Principalmente: pecho.');
    expect($text)->not->toContain('También:');
});

it('omits the "músculos trabajados" section entirely when the snapshot has no muscle data — never invented from the exercise name', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(['name' => 'Sentadilla búlgara'], exerciseOverrides: [
        'primary_muscle' => null,
        'secondary_muscles' => null,
    ]);

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    expect($text)->not->toContain('Músculos trabajados');
});

it('the "músculos trabajados" section appears right after the name/prescription and before the technique instructions', function () {
    $workoutExercise = makeWorkoutExerciseWithSnapshot(
        ['instructions' => ['Baja controlando']],
        exerciseOverrides: ['primary_muscle' => \App\Training\Enums\MuscleFocus::Back, 'secondary_muscles' => []],
    );

    $text = (new ExerciseMessageFormatter)->format($workoutExercise, 1);

    $musclePosition = strpos($text, 'Músculos trabajados');
    $techniquePosition = strpos($text, '📋 Técnica:');

    expect($musclePosition)->not->toBeFalse();
    expect($techniquePosition)->not->toBeFalse();
    expect($musclePosition)->toBeLessThan($techniquePosition);
});
