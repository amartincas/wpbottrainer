<?php

use App\Models\ExerciseLog;
use App\Models\ExerciseSet;
use App\Models\WorkoutExercise;
use App\Training\Enums\HistoryExerciseOutcome;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Support\SupportPhaseConfirmationDetector;

/**
 * Hito R1/R2/R3 — cobertura unitaria de las 3 fuentes de verdad
 * centralizadas en WorkoutExercise (requiresExecutionReport(),
 * isResolvedForSessionProgression(), historicalOutcome()) y del vocabulario
 * cerrado de SupportPhaseConfirmationDetector.
 */

// ── phase=main por defecto (migración/columna) ──

it('defaults phase to Main for a WorkoutExercise created without an explicit phase (migration DEFAULT)', function () {
    $workoutExercise = WorkoutExercise::factory()->create();

    expect($workoutExercise->fresh()->phase)->toBe(WorkoutExercisePhase::Main);
});

// ── requiresExecutionReport() ──

it('requiresExecutionReport() is true only for Main', function (WorkoutExercisePhase $phase, bool $expected) {
    $workoutExercise = WorkoutExercise::factory()->create(['phase' => $phase]);

    expect($workoutExercise->requiresExecutionReport())->toBe($expected);
})->with([
    'Main' => [WorkoutExercisePhase::Main, true],
    'Preparation' => [WorkoutExercisePhase::Preparation, false],
    'Cooldown' => [WorkoutExercisePhase::Cooldown, false],
]);

// ── isResolvedForSessionProgression() ──

it('isResolvedForSessionProgression() for Main requires an ExerciseLog — delivered_at alone is never enough', function () {
    $delivered = WorkoutExercise::factory()->create(['phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()]);
    expect($delivered->isResolvedForSessionProgression())->toBeFalse();

    ExerciseLog::factory()->create(['workout_exercise_id' => $delivered->id]);
    expect($delivered->fresh()->isResolvedForSessionProgression())->toBeTrue();
});

it('isResolvedForSessionProgression() for Preparation/Cooldown is resolved by delivered_at alone — never requires an ExerciseLog', function (WorkoutExercisePhase $phase) {
    $notDelivered = WorkoutExercise::factory()->create(['phase' => $phase, 'delivered_at' => null]);
    expect($notDelivered->isResolvedForSessionProgression())->toBeFalse();

    $delivered = WorkoutExercise::factory()->create(['phase' => $phase, 'delivered_at' => now()]);
    expect($delivered->isResolvedForSessionProgression())->toBeTrue();
    expect($delivered->exerciseLog)->toBeNull(); // nunca se crea un ExerciseLog falso
})->with([
    'Preparation' => [WorkoutExercisePhase::Preparation],
    'Cooldown' => [WorkoutExercisePhase::Cooldown],
]);

// ── historicalOutcome() ──

it('historicalOutcome() for Main reproduces the pre-existing 3-way derivation exactly', function () {
    $unreported = WorkoutExercise::factory()->create(['phase' => WorkoutExercisePhase::Main]);
    expect($unreported->historicalOutcome())->toBe(HistoryExerciseOutcome::Unreported);

    $skipped = WorkoutExercise::factory()->create(['phase' => WorkoutExercisePhase::Main]);
    ExerciseLog::factory()->create(['workout_exercise_id' => $skipped->id]);
    expect($skipped->fresh()->historicalOutcome())->toBe(HistoryExerciseOutcome::Skipped);

    $performed = WorkoutExercise::factory()->create(['phase' => WorkoutExercisePhase::Main]);
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $performed->id]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id]);
    expect($performed->fresh()->historicalOutcome())->toBe(HistoryExerciseOutcome::Performed);
});

it('historicalOutcome() for Preparation/Cooldown is Delivered when delivered, Unreported (defensive) when never delivered — never Performed/Skipped', function (WorkoutExercisePhase $phase) {
    $delivered = WorkoutExercise::factory()->create(['phase' => $phase, 'delivered_at' => now()]);
    expect($delivered->historicalOutcome())->toBe(HistoryExerciseOutcome::Delivered);

    $neverDelivered = WorkoutExercise::factory()->create(['phase' => $phase, 'delivered_at' => null]);
    expect($neverDelivered->historicalOutcome())->toBe(HistoryExerciseOutcome::Unreported);
})->with([
    'Preparation' => [WorkoutExercisePhase::Preparation],
    'Cooldown' => [WorkoutExercisePhase::Cooldown],
]);

// ── SupportPhaseConfirmationDetector — vocabulario cerrado ──

it('recognizes every approved confirmation phrase, normalized (trim/lowercase/punctuation)', function (string $body) {
    expect((new SupportPhaseConfirmationDetector)->isExplicitConfirmation($body))->toBeTrue();
})->with([
    'sí', 'si', 'listo', 'hecho', 'ok', 'okay', 'ya', 'terminé', 'termine',
    'continuar', 'siguiente', 'vamos', 'dale', 'sigamos', 'seguimos',
    'sigue', 'sigue adelante', 'vamos con el siguiente', 'ya está', 'ya esta',
    'he terminado', 'lo hice', 'lo hice ya',
    // Variantes de normalización (mayúsculas, espacios, puntuación).
    '  Listo  ', 'LISTO', '¡Listo!', 'Ya está.', 'Vamos,',
]);

it('never accepts a free message — a question or a help/safety-adjacent statement — as a confirmation', function (string $body) {
    expect((new SupportPhaseConfirmationDetector)->isExplicitConfirmation($body))->toBeFalse();
})->with([
    '¿cuánto dura?',
    '¿cómo hago este ejercicio?',
    'me duele la espalda',
    'no puedo hacerlo',
    '¿puedo cambiarlo?',
    'quiero otro ejercicio',
    // Sustring dentro de un mensaje más largo — coincidencia EXACTA, nunca parcial.
    'listo para empezar, pero antes tengo una duda',
    '',
]);
