<?php

use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Support\DurationEstimator;
use App\Training\Support\SessionIntroComposer;
use App\Training\Enums\WorkoutSessionStatus;

/**
 * Introducción de sesión — determinista, sin IA. `compose()` describe la
 * sesión YA prescrita, leyendo únicamente `WorkoutSession` (su
 * `prescription_context_snapshot` y el `exercise_snapshot` congelado de cada
 * `WorkoutExercise`) — nunca `TrainingProfile` vivo, nunca `decided_focus`
 * (ver hallazgo de staging: para full_body es el universo completo de los 6
 * `Exercise.muscle_group`, nunca una lista priorizada), nunca vuelve a
 * ejecutar TrainingEngine.
 *
 * Decisión de diseño (segunda revisión post-E2E): la introducción NUNCA
 * comunica una INTENCIÓN de selección como si fuera un resultado
 * garantizado — ni siquiera cuando `primary_focus`/`secondary_focus` están
 * declarados, porque `TrainingEngine::selectExercises()` solo garantiza
 * `ceil(N/2)` como mínimo, sin techo, y puede fallar silenciosamente con
 * catálogo escaso (ver investigación de casos A/B/C). Por eso el único
 * "focus" que la introducción anuncia es: (a) "todo el cuerpo" cuando
 * split_type=full_body sin ningún foco declarado, o (b) los músculos REALES
 * de los WorkoutExercise ya seleccionados, en cualquier otro caso.
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
            // split_type != full_body por defecto — el caso "full_body sin
            // foco explícito" (→ "todo el cuerpo") se prueba aparte,
            // explícitamente. decided_focus se deja presente a propósito en
            // varios tests para demostrar que YA NO se consulta.
            'split_type' => 'upper_lower',
            'primary_focus' => [],
            'secondary_focus' => [],
            'decided_focus' => 'chest,shoulders',
            'generated_at' => now()->toISOString(),
        ],
    ], $sessionOverrides));
}

/**
 * `WorkoutExerciseFactory::definition()` crea un `Exercise` interno al azar
 * y deriva `exercise_snapshot` de ÉL (incluso si se sobrescribe
 * `exercise_id`) — para controlar el `primary_muscle` real que ve
 * `SessionIntroComposer`, hay que sobrescribir `exercise_snapshot`
 * directamente, con solo las claves que la introducción realmente lee.
 */
function workoutExerciseWithMuscle(WorkoutSession $session, int $order, ?string $primaryMuscle): WorkoutExercise
{
    return WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id,
        'order' => $order,
        'exercise_snapshot' => ['name' => "Ejercicio {$order}", 'primary_muscle' => $primaryMuscle],
    ]);
}

// ── focus: describe los músculos REALES de la sesión, nunca decided_focus ──

it('describes the real primary muscles of the selected exercises, never decided_focus', function () {
    // Ejemplo del hallazgo E2E real: decided_focus del snapshot dice
    // 'chest,shoulders', pero los ejercicios REALMENTE seleccionados
    // trabajan hombros/cuádriceps/pantorrillas — la introducción debe
    // reflejar la realidad, no lo que decided_focus sugeriría.
    $session = introSession();
    workoutExerciseWithMuscle($session, 1, 'shoulders');
    workoutExerciseWithMuscle($session, 2, 'quads');
    workoutExerciseWithMuscle($session, 3, 'calves');

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos hombros, cuádriceps y pantorrillas.');
    expect($text)->not->toContain('pecho'); // decided_focus decía 'chest,shoulders' — nunca se usa
});

it('caps the number of real muscles mentioned to 3, in the order the exercises were delivered', function () {
    $session = introSession();
    workoutExerciseWithMuscle($session, 1, 'shoulders');
    workoutExerciseWithMuscle($session, 2, 'quads');
    workoutExerciseWithMuscle($session, 3, 'calves');
    workoutExerciseWithMuscle($session, 4, 'chest');

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos hombros, cuádriceps y pantorrillas.');
    expect($text)->not->toContain('pecho'); // 4to músculo distinto, fuera del cap de 3
});

it('Hito R1/R2/R3 — excludes Preparation/Cooldown from both the exercise count and the real-focus line, but still includes their time in the duration estimate', function () {
    $session = introSession();
    // Preparación con un músculo bien distinto (nunca debe aparecer en la
    // línea de foco, ni contar en "💪 N ejercicios") — sets/rest/duración
    // realistas de un ejercicio de apoyo (ver TrainingEngine::prescribeSupportExercise()).
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'order' => 1,
        'phase' => \App\Training\Enums\WorkoutExercisePhase::Preparation,
        'exercise_snapshot' => ['name' => 'Movilidad de tobillo', 'primary_muscle' => 'calves'],
        'prescribed_sets' => 1, 'prescribed_reps' => null, 'prescribed_load' => null,
        'prescribed_duration_seconds' => 90, 'rest_seconds' => 0,
    ]);
    workoutExerciseWithMuscle($session, 2, 'shoulders');
    workoutExerciseWithMuscle($session, 3, 'quads');
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'order' => 4,
        'phase' => \App\Training\Enums\WorkoutExercisePhase::Cooldown,
        'exercise_snapshot' => ['name' => 'Estiramiento de espalda', 'primary_muscle' => 'back'],
        'prescribed_sets' => 1, 'prescribed_reps' => null, 'prescribed_load' => null,
        'prescribed_duration_seconds' => 90, 'rest_seconds' => 0,
    ]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    // Solo los 2 Main cuentan y solo sus músculos aparecen — nunca
    // "pantorrillas" (Preparación) ni "espalda" (Cooldown).
    expect($text)->toContain('💪 2 ejercicios');
    expect($text)->toContain('Hoy trabajaremos hombros y cuádriceps.');
    expect($text)->not->toContain('pantorrillas');
    expect($text)->not->toContain('espalda');

    // Duración SÍ suma las 3 fases: 2×540s (Main, default de
    // WorkoutExerciseFactory: 3 series×(120+60)s) + 2×90s (apoyo) = 1260s
    // = 21 min.
    expect($text)->toContain('⏱️ Duración aproximada: 21 minutos');
});

it('deduplicates repeated real muscles instead of counting them twice toward the cap', function () {
    $session = introSession();
    workoutExerciseWithMuscle($session, 1, 'quads');
    workoutExerciseWithMuscle($session, 2, 'quads'); // mismo músculo que el anterior
    workoutExerciseWithMuscle($session, 3, 'calves');

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos cuádriceps y pantorrillas.');
});

// ── caso E2E real: full_body sin foco explícito (bug confirmado en staging) ──

it('full_body with no explicit primary/secondary focus communicates "todo el cuerpo", never a list of muscles', function () {
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
    // Aunque los ejercicios reales sí tengan músculos identificables, el
    // caso full_body-sin-foco tiene prioridad: no hay ningún foco genuino
    // que describir, ni el universal de decided_focus ni un subconjunto real.
    workoutExerciseWithMuscle($session, 1, 'shoulders');
    workoutExerciseWithMuscle($session, 2, 'quads');
    workoutExerciseWithMuscle($session, 3, 'calves');

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos todo el cuerpo.');
    expect($text)->not->toContain('cuádriceps')->not->toContain('pantorrillas')->not->toContain('hombros');
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

// ── foco explícito declarado pero NO garantizado: nunca prometer "principalmente X" ──

it('with an explicit primary_focus, describes the real exercises actually selected, never promising "principalmente X"', function () {
    // primary_focus=['chest'] está declarado, pero — como confirma la
    // investigación de TrainingEngine::selectExercises() — eso NUNCA
    // garantiza que la sesión termine siendo de pecho: aquí los ejercicios
    // REALES terminaron siendo de hombros/cuádriceps/pantorrillas. La
    // introducción debe describir eso, nunca prometer "principalmente pecho".
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
    workoutExerciseWithMuscle($session, 1, 'shoulders');
    workoutExerciseWithMuscle($session, 2, 'quads');
    workoutExerciseWithMuscle($session, 3, 'calves');

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos hombros, cuádriceps y pantorrillas.');
    expect($text)->not->toContain('principalmente');
    expect($text)->not->toContain('pecho'); // primary_focus declarado, pero NUNCA garantizado ni prometido
    expect($text)->not->toContain('todo el cuerpo'); // hay foco explícito declarado, no es el Caso 1
});

it('with only a secondary_focus declared (primary_focus empty), still describes the real exercises, never a promised focus', function () {
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
    workoutExerciseWithMuscle($session, 1, 'back');
    workoutExerciseWithMuscle($session, 2, 'triceps');

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos espalda y tríceps.');
    expect($text)->not->toContain('principalmente');
    expect($text)->not->toContain('todo el cuerpo');
});

// ── músculos que antes (vocabulario grueso) no tenían traducción: ya no aplica, pero se conserva la cobertura fina ──

it('never silently drops a real muscle from the families formerly uncovered by the coarse vocabulary (arms/core/legs)', function (string $realMuscle, string $expectedLabel) {
    // Hallazgo histórico ya resuelto de otra forma: el vocabulario GRUESO
    // (arms/core/legs) ya no se usa en absoluto para la introducción — se
    // describe siempre el músculo FINO real de cada ejercicio, y
    // ExerciseMessageFormatter::MUSCLE_LABELS cubre los 11 valores finos
    // sin ningún hueco (biceps/triceps ⊂ "arms", abs ⊂ "core", glutes/
    // quads/hamstrings/calves ⊂ "legs" de la taxonomía gruesa anterior).
    // Este test confirma que ninguna de esas familias desaparece.
    $session = introSession();
    workoutExerciseWithMuscle($session, 1, $realMuscle);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain("Hoy trabajaremos {$expectedLabel}.");
})->with([
    'biceps (familia "arms")' => ['biceps', 'bíceps'],
    'abs (familia "core")' => ['abs', 'abdomen'],
    'quads (familia "legs")' => ['quads', 'cuádriceps'],
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

// ── fuente de datos: snapshots congelados, nunca TrainingProfile vivo ni decided_focus ──

it('never depends on the live TrainingProfile — reflects the frozen exercise_snapshot even if the profile changed afterward', function () {
    $session = introSession();
    workoutExerciseWithMuscle($session, 1, 'back');

    // El TrainingProfile del contacto cambia DESPUÉS de generar la sesión —
    // no debe afectar en nada la introducción, que ya fue prescrita.
    $contact = $session->contact;
    \App\Models\TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'goal' => \App\Training\Enums\TrainingGoal::BuildMuscle,
        'primary_focus' => [\App\Training\Enums\MuscleFocus::Chest->value],
    ]);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('espalda'); // sigue siendo el músculo real congelado, no lo que el perfil vivo diga ahora
    expect($text)->not->toContain('pecho');
});

it('never consults decided_focus as a source of visible priorities — an unrelated decided_focus never leaks into the intro', function () {
    // decided_focus dice 'chest,shoulders' (vía introSession() por defecto),
    // pero el músculo real del único ejercicio de la sesión es 'quads' —
    // sin foco explícito y con split_type != full_body, la introducción
    // debe describir 'quads', nunca nada derivado de decided_focus.
    $session = introSession();
    workoutExerciseWithMuscle($session, 1, 'quads');

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->toContain('Hoy trabajaremos cuádriceps.');
    expect($text)->not->toContain('pecho')->not->toContain('hombros');
});

it('gracefully omits the focus line when no exercise has an identifiable primary muscle, without inventing one', function () {
    $session = introSession();
    workoutExerciseWithMuscle($session, 1, null);

    $text = sessionIntroComposer()->compose($session->fresh('workoutExercises'));

    expect($text)->not->toContain('Hoy trabajaremos');
    expect($text)->toContain('💪 1 ejercicio'); // el resto de la introducción sigue funcionando
});
