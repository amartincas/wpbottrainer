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
            // split_type != full_body por defecto: 'chest,shoulders' es un
            // subconjunto genuino de una fase real de rotación (ej.
            // upper_lower), no el universo completo de full_body — el caso
            // "full_body sin foco explícito" se prueba aparte, explícitamente.
            'split_type' => 'upper_lower',
            'primary_focus' => [],
            'secondary_focus' => [],
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
    // 'arms,back,chest,shoulders' es un decided_focus REAL de una fase
    // upper_lower (TrainingEngine::ROTATIONS['upper_lower'][0]) — un
    // subconjunto genuino (4 de 6 grupos), no el universo completo de
    // full_body (ver el caso aparte "full body sin foco explícito" más
    // abajo, donde truncar SÍ sería engañoso). Confirma el cap de 3 en un
    // caso donde decided_focus realmente acota la selección.
    $session = introSession([
        'prescription_context_snapshot' => [
            'schema_version' => 1,
            'goal' => 'general_fitness',
            'split_type' => 'upper_lower',
            'primary_focus' => [],
            'secondary_focus' => [],
            'decided_focus' => 'arms,back,chest,shoulders',
        ],
    ]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    // Máximo 3 etiquetas — los 3 primeros grupos del snapshot, en orden.
    expect($text)->toContain('brazos')->toContain('espalda')->toContain('pecho');
    expect($text)->not->toContain('hombros'); // 4to grupo, fuera del cap de 3
});

// ── caso E2E real: full_body sin foco explícito (bug confirmado en staging) ──

it('full_body with no explicit primary/secondary focus communicates "todo el cuerpo", never 3 arbitrary muscles', function () {
    // Reproduce exactamente el snapshot real de staging que causó el bug:
    // decided_focus es el universo completo de ROTATIONS['full_body'] (los
    // 6 grupos, sin orden de prioridad real) — la sesión real seleccionó
    // shoulders/quads/calves, 0 de los 3 "primeros" (arms/back/chest).
    $session = introSession([
        'prescription_context_snapshot' => [
            'schema_version' => 1,
            'goal' => 'general_fitness',
            'split_type' => 'full_body',
            'primary_focus' => [],
            'secondary_focus' => [],
            'decided_focus' => 'arms,back,chest,core,legs,shoulders',
        ],
    ]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos todo el cuerpo.');
    expect($text)->not->toContain('brazos, espalda y pecho');
    expect($text)->not->toContain('trabajaremos principalmente');
});

it('full_body with an explicit primary_focus still shows the corresponding focus (Case 2), never "todo el cuerpo"', function () {
    // Mismo split_type=full_body y mismo decided_focus universal que el
    // caso anterior — la única diferencia es que el perfil SÍ declaró un
    // foco real. "Conservar el comportamiento actual" significa seguir
    // traduciendo decided_focus tal como antes; no cambia por tener
    // primary_focus explícito (ese es un mecanismo paralelo e independiente
    // en TrainingEngine::selectExercises(), fuera de este parche).
    $session = introSession([
        'prescription_context_snapshot' => [
            'schema_version' => 1,
            'goal' => 'general_fitness',
            'split_type' => 'full_body',
            'primary_focus' => ['chest'],
            'secondary_focus' => [],
            'decided_focus' => 'arms,back,chest,core,legs,shoulders',
        ],
    ]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos principalmente');
    expect($text)->not->toContain('todo el cuerpo');
});

it('full_body with only secondary_focus declared (primary_focus empty) still shows the corresponding focus (Case 2)', function () {
    $session = introSession([
        'prescription_context_snapshot' => [
            'schema_version' => 1,
            'goal' => 'general_fitness',
            'split_type' => 'full_body',
            'primary_focus' => [],
            'secondary_focus' => ['biceps'],
            'decided_focus' => 'arms,back,chest,core,legs,shoulders',
        ],
    ]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos principalmente');
    expect($text)->not->toContain('todo el cuerpo');
});

it('the "todo el cuerpo" case never changes the real exercise count or duration of the session', function () {
    $session = introSession([
        'prescription_context_snapshot' => [
            'schema_version' => 1,
            'goal' => 'general_fitness',
            'split_type' => 'full_body',
            'primary_focus' => [],
            'secondary_focus' => [],
            'decided_focus' => 'arms,back,chest,core,legs,shoulders',
        ],
    ]);
    // 3 × (120+60) = 540s = 9 min por ejercicio, 3 ejercicios reales.
    for ($order = 1; $order <= 3; $order++) {
        WorkoutExercise::factory()->create([
            'workout_session_id' => $session->id,
            'order' => $order,
            'prescribed_sets' => 3,
            'rest_seconds' => 60,
            'prescribed_duration_seconds' => null,
        ]);
    }

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos todo el cuerpo.');
    expect($text)->toContain('💪 3 ejercicios');
    expect($text)->toContain('⏱️ Duración aproximada: 27 minutos');
});

it('never silently drops a focus whose coarse muscle_group is arms, core or legs, when it corresponds to an explicit focus', function (string $decidedFocus, string $expectedLabel) {
    // Hallazgo del reporte anterior, ahora corregido: ExerciseMessageFormatter::
    // MUSCLE_GROUP_LABELS cubre los 6 valores exactos que TrainingEngine::
    // ROTATIONS puede producir — ningún decided_focus desaparece silenciosamente.
    // primary_focus explícito + split_type != full_body: Case 2 (foco real),
    // no el caso "todo el cuerpo" agregado por este parche.
    $session = introSession([
        'prescription_context_snapshot' => [
            'schema_version' => 1,
            'goal' => 'general_fitness',
            'split_type' => 'push_pull_legs',
            'primary_focus' => ['biceps'],
            'secondary_focus' => [],
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
