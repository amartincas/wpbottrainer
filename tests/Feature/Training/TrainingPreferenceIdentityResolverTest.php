<?php

use App\Models\Exercise;
use App\Training\Enums\PreferenceDimension;
use App\Training\Support\TrainingPreferenceIdentityResolver;

/**
 * Hito B3 (diseño v3 FINAL, Sección A.4/6) — resolución de identidad.
 * Principio probado: nunca se adivina — solo se resuelve automáticamente
 * con match único tras la normalización permitida; cualquier otro caso cae
 * en clarificación (nunca resolución silenciosa).
 */
function preferenceIdentityResolver(): TrainingPreferenceIdentityResolver
{
    return new TrainingPreferenceIdentityResolver;
}

it('resolves automatically on an exact match (case/accent-insensitive)', function () {
    $exercise = Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('sentadilla', null);

    expect($resolution->status)->toBe('resolved');
    expect($resolution->dimension)->toBe(PreferenceDimension::Exercise);
    expect($resolution->exerciseId)->toBe($exercise->id);
});

it('resolves automatically via the singular/plural toggle when the plural form matches uniquely', function () {
    $exercise = Exercise::factory()->create(['name' => 'Burpee', 'name_es' => 'Burpee', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('burpees', null);

    expect($resolution->status)->toBe('resolved');
    expect($resolution->exerciseId)->toBe($exercise->id);
});

it('does NOT auto-resolve via internal preposition normalization ("press banca" vs. "Press de banca")', function () {
    Exercise::factory()->create(['name' => 'Bench Press', 'name_es' => 'Press de banca', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('press banca', null);

    expect($resolution->status)->not->toBe('resolved');
});

it('offers clarification options via partial/token match when no exact match exists', function () {
    Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Sumo Squat', 'name_es' => 'Sentadilla sumo', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Bulgarian Squat', 'name_es' => 'Sentadilla búlgara', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('sentadilla con salto', null);

    expect($resolution->status)->toBe('clarify');
    expect($resolution->clarificationOptions)->toContain('Sentadilla', 'Sentadilla sumo', 'Sentadilla búlgara');
});

it('resolves a single exact match automatically even when other candidates share a token (no false ambiguity)', function () {
    // "sentadilla" NO debe considerarse ambiguo solo porque "Sentadilla sumo"
    // también existe — la comparación es EXACTA, no substring, así que un
    // match único sigue siendo automático (Sección A.4/revisión v3, punto 4).
    $exercise = Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Sumo Squat', 'name_es' => 'Sentadilla sumo', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('sentadilla', null);

    expect($resolution->status)->toBe('resolved');
    expect($resolution->exerciseId)->toBe($exercise->id);
});

// ── Fix post-E2E real (staging, Contact 26): partialMatches() ahora
// compara TAMBIÉN la alternancia singular/plural del candidato, no solo
// exactMatches(). Antes de este fix, "sentadillas" (plural) nunca
// compartía token/subcadena con nombres compuestos que usan la forma
// singular como núcleo ("Sentadilla con banda"), dejando la lista de
// clarificación incompleta — reproducido primero con el catálogo real
// (7 ejercicios "Sentadilla..." activos, solo 1 aparecía como opción). ──

it('Caso 1: a plural candidate ("sentadillas") now offers every compound singular-named match', function () {
    Exercise::factory()->create(['name' => 'Banded Squat', 'name_es' => 'Sentadilla con banda', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Bodyweight Squat', 'name_es' => 'Sentadilla con peso corporal', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('sentadillas', null);

    expect($resolution->status)->toBe('clarify');
    expect($resolution->clarificationOptions)->toContain('Sentadilla con banda', 'Sentadilla con peso corporal');
});

it('Caso 2: the singular candidate ("sentadilla") produces the exact same set of options as the plural form', function () {
    Exercise::factory()->create(['name' => 'Banded Squat', 'name_es' => 'Sentadilla con banda', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Bodyweight Squat', 'name_es' => 'Sentadilla con peso corporal', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('sentadilla', null);

    expect($resolution->status)->toBe('clarify');
    expect($resolution->clarificationOptions)->toContain('Sentadilla con banda', 'Sentadilla con peso corporal');
});

it('Caso 3: a multi-word candidate matching one compound name exactly still resolves automatically via exactMatches(), never via partial', function () {
    $exercise = Exercise::factory()->create(['name' => 'Sumo Squat', 'name_es' => 'Sentadillas sumo', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Banded Squat', 'name_es' => 'Sentadilla con banda', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('sentadillas sumo', null);

    expect($resolution->status)->toBe('resolved');
    expect($resolution->exerciseId)->toBe($exercise->id);
});

it('Caso 4: partialMatches() never produces "resolved", even when several candidates match via the toggled form', function () {
    Exercise::factory()->create(['name' => 'Banded Squat', 'name_es' => 'Sentadilla con banda', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Bodyweight Squat', 'name_es' => 'Sentadilla con peso corporal', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Bulgarian Squat with Dumbbell', 'name_es' => 'Sentadilla búlgara con mancuernas', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('sentadillas', null);

    expect($resolution->status)->toBe('clarify');
    expect($resolution->dimension)->toBeNull();
    expect($resolution->exerciseId)->toBeNull();
    expect($resolution->clarificationOptions)->toHaveCount(3);
});

it('Caso 5: a single similar-but-different candidate found only via the toggle still never auto-resolves', function () {
    // "sentadillas" (plural) -> alternancia -> "sentadilla" (singular);
    // "Sentadilla búlgara con mancuernas" comparte el token "sentadilla"
    // pero es un ejercicio genuinamente distinto de lo que el usuario pidió
    // — debe seguir siendo una OPCIÓN de clarificación (aunque sea la
    // única), nunca una resolución automática.
    Exercise::factory()->create(['name' => 'Bulgarian Squat with Dumbbell', 'name_es' => 'Sentadilla búlgara con mancuernas', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('sentadillas', null);

    expect($resolution->status)->toBe('clarify');
    expect($resolution->exerciseId)->toBeNull();
    expect($resolution->clarificationOptions)->toBe(['Sentadilla búlgara con mancuernas']);
});

// ── Fix post-E2E real (hallazgo de MAX_CLARIFICATION_OPTIONS): orden
// determinista por id ascendente + totalMatches, sin cortar el recorrido
// del catálogo al llenar las 5 opciones. ──

it('Caso A (orden determinista): clarificationOptions queda ordenado por id ascendente, sin importar el orden de inserción', function () {
    // IDs explícitos, deliberadamente en el orden INVERSO al que deben
    // aparecer — si el resolver no ordenara por id, este test fallaría de
    // forma no determinista según el orden físico de la BD.
    Exercise::factory()->create(['id' => 300, 'name' => 'C Squat', 'name_es' => 'Sentadilla C', 'is_active' => true]);
    Exercise::factory()->create(['id' => 100, 'name' => 'A Squat', 'name_es' => 'Sentadilla A', 'is_active' => true]);
    Exercise::factory()->create(['id' => 200, 'name' => 'B Squat', 'name_es' => 'Sentadilla B', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('sentadillas', null);

    expect($resolution->status)->toBe('clarify');
    expect($resolution->clarificationOptions)->toBe(['Sentadilla A', 'Sentadilla B', 'Sentadilla C']);
});

it('Caso B: exactamente 5 coincidencias -> 5 opciones, totalMatches=5, sin señal de "hay más"', function () {
    foreach (range(1, 5) as $i) {
        Exercise::factory()->create(['id' => 100 + $i, 'name' => "Squat {$i}", 'name_es' => "Sentadilla {$i}", 'is_active' => true]);
    }

    $resolution = preferenceIdentityResolver()->resolve('sentadillas', null);

    expect($resolution->status)->toBe('clarify');
    expect($resolution->clarificationOptions)->toHaveCount(5);
    expect($resolution->totalMatches)->toBe(5);
    expect($resolution->hasMoreMatches())->toBeFalse();
});

it('Caso C: 6 coincidencias -> 5 opciones, totalMatches=6, hasMoreMatches=true', function () {
    foreach (range(1, 6) as $i) {
        Exercise::factory()->create(['id' => 100 + $i, 'name' => "Squat {$i}", 'name_es' => "Sentadilla {$i}", 'is_active' => true]);
    }

    $resolution = preferenceIdentityResolver()->resolve('sentadillas', null);

    expect($resolution->status)->toBe('clarify');
    expect($resolution->clarificationOptions)->toHaveCount(5);
    expect($resolution->totalMatches)->toBe(6);
    expect($resolution->hasMoreMatches())->toBeTrue();
    // Determinismo: las 5 mostradas son siempre las de menor id (1-5), nunca 2-6 ni otra combinación.
    expect($resolution->clarificationOptions)->toBe(['Sentadilla 1', 'Sentadilla 2', 'Sentadilla 3', 'Sentadilla 4', 'Sentadilla 5']);
});

it('Caso D: 8 coincidencias -> 5 opciones, totalMatches=8, hasMoreMatches=true', function () {
    foreach (range(1, 8) as $i) {
        Exercise::factory()->create(['id' => 100 + $i, 'name' => "Squat {$i}", 'name_es' => "Sentadilla {$i}", 'is_active' => true]);
    }

    $resolution = preferenceIdentityResolver()->resolve('sentadillas', null);

    expect($resolution->status)->toBe('clarify');
    expect($resolution->clarificationOptions)->toHaveCount(5);
    expect($resolution->totalMatches)->toBe(8);
    expect($resolution->hasMoreMatches())->toBeTrue();
});

it('Caso E: alcanzar MAX_CLARIFICATION_OPTIONS no corta el recorrido — coincidencias más allá de la 6ª siguen contabilizadas', function () {
    // 7 coincidencias: si el foreach se cortara al llegar a 5 opciones
    // llenas (comportamiento ANTERIOR al fix), totalMatches quedaría en 5
    // o 6 según dónde se cortara — nunca en el valor real (7).
    foreach (range(1, 7) as $i) {
        Exercise::factory()->create(['id' => 100 + $i, 'name' => "Squat {$i}", 'name_es' => "Sentadilla {$i}", 'is_active' => true]);
    }

    $resolution = preferenceIdentityResolver()->resolve('sentadillas', null);

    expect($resolution->totalMatches)->toBe(7);
    expect($resolution->clarificationOptions)->toHaveCount(5);
});

it('Caso F (real conceptual): "No me gustan las sentadillas" contra un catálogo local con 6 variantes sigue siendo clarify, nunca resolved', function () {
    // Nombres inspirados en el catálogo real ya auditado (Sección C del
    // hallazgo de #1072) — usando ÚNICAMENTE fixtures/factory locales, sin
    // ningún id de producción/staging.
    $names = [
        'Sentadilla con banda',
        'Sentadilla con peso corporal',
        'Sentadilla búlgara con mancuernas',
        'Máquina de sentadilla con cinturón Cuads',
        'Sentadilla de glúteos en máquina Smith',
        'Sentadillas sumo',
    ];

    foreach ($names as $name) {
        Exercise::factory()->create(['name' => $name, 'name_es' => $name, 'is_active' => true]);
    }

    $classification = (new \App\Training\Support\TrainingPreferenceMessageClassifier)->classify('No me gustan las sentadillas');
    $resolution = preferenceIdentityResolver()->resolve($classification->candidateTerm, null);

    expect($classification->category)->toBe(\App\Training\Enums\PreferenceMessageCategory::Dislike);
    expect($resolution->status)->toBe('clarify');
    expect($resolution->status)->not->toBe('resolved');
    expect($resolution->totalMatches)->toBe(6);
    expect($resolution->clarificationOptions)->toHaveCount(5);
    expect($resolution->hasMoreMatches())->toBeTrue();
});

it('returns unresolved when there is no match at all, not even partial', function () {
    Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);

    $resolution = preferenceIdentityResolver()->resolve('malabares', null);

    expect($resolution->status)->toBe('unresolved');
});

it('never matches an inactive exercise', function () {
    Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => false]);

    $resolution = preferenceIdentityResolver()->resolve('sentadilla', null);

    expect($resolution->status)->not->toBe('resolved');
});

// ── Camino 1: ancla de contexto activo (sin candidateTerm) ──

it('resolves via the front-exercise anchor when no candidate term was extracted', function () {
    $resolution = preferenceIdentityResolver()->resolve(null, [
        'workout_exercise_id' => 99, 'exercise_id' => 413, 'name' => 'Burpee', 'requires_report' => true,
    ]);

    expect($resolution->status)->toBe('resolved');
    expect($resolution->dimension)->toBe(PreferenceDimension::Exercise);
    expect($resolution->exerciseId)->toBe(413);
});

it('is unresolved (never guesses) when there is no candidate term AND no front exercise', function () {
    $resolution = preferenceIdentityResolver()->resolve(null, null);

    expect($resolution->status)->toBe('unresolved');
});

it('is unresolved when the front exercise anchor has no exercise_id (defensive)', function () {
    $resolution = preferenceIdentityResolver()->resolve('', ['workout_exercise_id' => 1, 'exercise_id' => null, 'name' => 'X', 'requires_report' => false]);

    expect($resolution->status)->toBe('unresolved');
});

// ── Equipment ──

it('resolves a closed-vocabulary equipment term', function (string $term, string $expectedValue) {
    $resolution = preferenceIdentityResolver()->resolve($term, null);

    expect($resolution->status)->toBe('resolved');
    expect($resolution->dimension)->toBe(PreferenceDimension::Equipment);
    expect($resolution->equipmentValue)->toBe($expectedValue);
})->with([
    ['mancuernas', 'dumbbells'],
    ['mancuerna', 'dumbbells'],
    ['barra', 'barbell'],
    ['banco', 'bench'],
]);

it('tries equipment vocabulary before falling back to exercise catalog resolution', function () {
    // "mancuernas" no debe intentar resolverse contra el catálogo de
    // Exercise en absoluto — el vocabulario cerrado de equipment gana.
    $resolution = preferenceIdentityResolver()->resolve('mancuernas', null);

    expect($resolution->dimension)->toBe(PreferenceDimension::Equipment);
});
