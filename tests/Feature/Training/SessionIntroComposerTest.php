<?php

use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Support\DurationEstimator;
use App\Training\Support\SessionIntroComposer;
use App\Training\Enums\WorkoutSessionStatus;

/**
 * Introducción de sesión — determinista, sin IA. `compose()` describe la
 * sesión YA prescrita, leyendo únicamente `WorkoutSession` (y su
 * `prescription_context_snapshot` congelado) — nunca `TrainingProfile` vivo,
 * nunca recalcula el focus, nunca vuelve a ejecutar TrainingEngine.
 */
function sessionIntroComposer(): SessionIntroComposer
{
    return new SessionIntroComposer(new DurationEstimator);
}

function introSession(array $sessionOverrides = []): WorkoutSession
{
    return WorkoutSession::factory()->create(array_merge([
        'status' => WorkoutSessionStatus::Scheduled,
        'prescription_context_snapshot' => [
            'schema_version' => 1,
            'goal' => 'general_fitness',
            'decided_focus' => 'chest,shoulders',
            'generated_at' => now()->toISOString(),
        ],
    ], $sessionOverrides));
}

// ── focus ──

it('communicates the decided focus, translated from the frozen snapshot', function () {
    $session = introSession();
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('pecho')->toContain('hombros');
});

it('caps the number of focus labels mentioned, joining naturally', function () {
    // 'arms,back,chest,core,legs,shoulders' es el decided_focus REAL que
    // produce TrainingEngine::ROTATIONS para la rotación full_body (los 6
    // valores completos) — confirma el cap de 3 usando el vocabulario grueso
    // real, no uno inventado para el test.
    $session = introSession([
        'prescription_context_snapshot' => [
            'schema_version' => 1,
            'goal' => 'general_fitness',
            'decided_focus' => 'arms,back,chest,core,legs,shoulders',
        ],
    ]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    // Máximo 3 etiquetas — los 3 primeros grupos del snapshot, en orden.
    expect($text)->toContain('brazos')->toContain('espalda')->toContain('pecho');
    expect($text)->not->toContain('core')->not->toContain('piernas')->not->toContain('hombros'); // 4to-6to grupo, fuera del cap de 3
});

it('never silently drops a focus whose coarse muscle_group is arms, core or legs', function (string $decidedFocus, string $expectedLabel) {
    // Hallazgo del reporte anterior, ahora corregido: ExerciseMessageFormatter::
    // MUSCLE_GROUP_LABELS cubre los 6 valores exactos que TrainingEngine::
    // ROTATIONS puede producir — ningún decided_focus desaparece silenciosamente.
    $session = introSession([
        'prescription_context_snapshot' => [
            'schema_version' => 1,
            'goal' => 'general_fitness',
            'decided_focus' => $decidedFocus,
        ],
    ]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos principalmente')->toContain($expectedLabel);
})->with([
    'arms' => ['arms', 'brazos'],
    'core' => ['core', 'core'],
    'legs' => ['legs', 'piernas'],
]);

// ── cantidad ──

it('communicates the real exercise count of the session', function () {
    $session = introSession();
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 2]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 3]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('💪 3 ejercicios');
});

it('uses the singular form for exactly 1 exercise', function () {
    $session = introSession();
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('💪 1 ejercicio')->not->toContain('1 ejercicios');
});

it('describes the session actually generated, never Tenant.target_session_duration_minutes directly — catálogo insuficiente', function () {
    // Simula lo que produce TrainingEngine cuando el catálogo elegible no
    // alcanza lo pedido por duración objetivo (ver TrainingEngineTest.php
    // > "when the eligible catalog has fewer exercises..."): Tenant pide 60
    // minutos (que en general_fitness pediría 7 ejercicios), pero la sesión
    // real quedó con solo 4. La introducción debe describir esos 4 reales y
    // su duración real — nunca "7 ejercicios"/"60 minutos".
    $session = introSession();
    $session->contact->tenant()->update(['target_session_duration_minutes' => 60]);

    for ($order = 1; $order <= 4; $order++) {
        WorkoutExercise::factory()->create([
            'workout_session_id' => $session->id,
            'order' => $order,
            'prescribed_sets' => 3,
            'rest_seconds' => 60,
            'prescribed_duration_seconds' => null,
        ]);
    }

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    // Real: 4 ejercicios × (3×(120+60))=540s = 2160s = 36 min.
    expect($text)->toContain('💪 4 ejercicios')->toContain('⏱️ Duración aproximada: 36 minutos');
    expect($text)->not->toContain('7 ejercicios')->not->toContain('60 minutos');
});

// ── duración ──

it('communicates the real estimated duration, computed from the persisted prescriptions', function () {
    $session = introSession();
    // 3 × (120+60) = 540s = 9 min
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'prescribed_sets' => 3, 'rest_seconds' => 60, 'prescribed_duration_seconds' => null]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('⏱️ Duración aproximada: 9 minutos');
});

// ── fuente de datos: snapshot congelado, nunca TrainingProfile vivo ──

it('never depends on the live TrainingProfile — reflects the frozen snapshot even if the profile changed afterward', function () {
    $session = introSession([
        'prescription_context_snapshot' => [
            'schema_version' => 1,
            'goal' => 'general_fitness',
            'decided_focus' => 'back',
        ],
    ]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);

    // El TrainingProfile del contacto cambia DESPUÉS de generar la sesión —
    // no debe afectar en nada la introducción, que ya fue prescrita.
    $contact = $session->contact;
    \App\Models\TrainingProfile::factory()->create(['contact_id' => $contact->id, 'goal' => \App\Training\Enums\TrainingGoal::BuildMuscle]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('espalda'); // sigue siendo 'back' del snapshot, no lo que sea que el perfil diga ahora
});

it('gracefully omits the focus line when the snapshot has no decided_focus, without inventing one', function () {
    $session = introSession(['prescription_context_snapshot' => ['schema_version' => 1, 'goal' => 'general_fitness']]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->not->toContain('Hoy trabajaremos principalmente');
    expect($text)->toContain('💪 1 ejercicio'); // el resto de la introducción sigue funcionando
});
