<?php

use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Support\DurationEstimator;

/**
 * Duración objetivo (Objetivo de producto, ver docs de la auditoría) —
 * `DurationEstimator` es puramente aritmético: no selecciona, no decide
 * elegibilidad ni cantidad, no consulta IA. Estos tests solo verifican la
 * fórmula determinista, sobre datos ya reales/hipotéticos que otro decidió.
 */
function durationEstimator(): DurationEstimator
{
    return new DurationEstimator;
}

// ── 1: reps-based — sets × (120 + rest_seconds) ──

it('1: estimates a reps-based exercise as sets × (120 heuristic seconds + rest_seconds)', function () {
    $seconds = durationEstimator()->estimateExerciseSeconds(sets: 3, restSeconds: 60, durationSeconds: null);

    expect($seconds)->toBe(3 * (120 + 60)); // 540
});

it('varies correctly with a different goal-shaped prescription (build_muscle: sets=4, rest=90)', function () {
    $seconds = durationEstimator()->estimateExerciseSeconds(sets: 4, restSeconds: 90, durationSeconds: null);

    expect($seconds)->toBe(4 * (120 + 90)); // 840
});

// ── 2: time-based — usa durationSeconds real, sin heurística ──

it('2: a time-based exercise uses the real prescribed durationSeconds, never the 120s heuristic', function () {
    $seconds = durationEstimator()->estimateExerciseSeconds(sets: 3, restSeconds: 30, durationSeconds: 40);

    expect($seconds)->toBe(3 * (40 + 30)); // 210 — nunca 3*(120+30)
});

// ── 3: sesión — suma correctamente varios WorkoutExercise reales ──

it('3: estimateSessionSeconds() sums the real persisted prescriptions of every WorkoutExercise in the session', function () {
    $session = WorkoutSession::factory()->create();
    // reps-based: 3 × (120+60) = 540
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'order' => 1,
        'prescribed_sets' => 3, 'rest_seconds' => 60, 'prescribed_duration_seconds' => null,
    ]);
    // time-based: 3 × (40+30) = 210
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'order' => 2,
        'prescribed_sets' => 3, 'rest_seconds' => 30, 'prescribed_duration_seconds' => 40,
    ]);
    // reps-based con otra prescripción: 4 × (120+90) = 840
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'order' => 3,
        'prescribed_sets' => 4, 'rest_seconds' => 90, 'prescribed_duration_seconds' => null,
    ]);

    $total = durationEstimator()->estimateSessionSeconds($session->fresh('workoutExercises'));

    expect($total)->toBe(540 + 210 + 840); // 1590
});

it('estimateSessionSeconds() returns 0 for a session with no exercises, without throwing', function () {
    $session = WorkoutSession::factory()->create();

    expect(durationEstimator()->estimateSessionSeconds($session->fresh('workoutExercises')))->toBe(0);
});
