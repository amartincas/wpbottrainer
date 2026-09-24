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
