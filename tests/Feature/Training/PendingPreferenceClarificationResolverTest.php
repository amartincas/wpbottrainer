<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingPreferenceClarification;
use App\Training\Support\PendingPreferenceClarificationResolver;
use App\Training\Support\TrainingPreferenceIdentityResolver;

/**
 * Hito B3.1 — `PendingPreferenceClarificationResolver` es PURO por
 * aprobación explícita del diseño: nunca toca base de datos, nunca crea,
 * resuelve ni abandona ninguna `TrainingPreferenceClarification`, nunca
 * crea ninguna `TrainingPreference`. Solo delega en la API pública de
 * `TrainingPreferenceIdentityResolver::resolve()`, sin ningún cambio a esa
 * clase, y remapea su contrato de 3 estados.
 */
function pendingResolver(): PendingPreferenceClarificationResolver
{
    return new PendingPreferenceClarificationResolver(new TrainingPreferenceIdentityResolver);
}

function pendingResolverFixture(array $overrides = []): TrainingPreferenceClarification
{
    return TrainingPreferenceClarification::factory()->create(array_merge([
        'contact_id' => Contact::factory(),
    ], $overrides));
}

it('maps IdentityResolver "resolved" to outcome "resolved", carrying the resolution through', function () {
    $exercise = Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);
    $pending = pendingResolverFixture();

    $outcome = pendingResolver()->resolve($pending, 'sentadilla');

    expect($outcome->status)->toBe('resolved');
    expect($outcome->resolution->status)->toBe('resolved');
    expect($outcome->resolution->exerciseId)->toBe($exercise->id);
});

it('maps IdentityResolver "clarify" to outcome "ambiguous"', function () {
    Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Sumo Squat', 'name_es' => 'Sentadilla sumo', 'is_active' => true]);
    $pending = pendingResolverFixture();

    $outcome = pendingResolver()->resolve($pending, 'sentadilla con salto');

    expect($outcome->status)->toBe('ambiguous');
    expect($outcome->resolution->status)->toBe('clarify');
});

it('maps IdentityResolver "unresolved" to outcome "no_match"', function () {
    $pending = pendingResolverFixture();

    $outcome = pendingResolver()->resolve($pending, 'malabares');

    expect($outcome->status)->toBe('no_match');
    expect($outcome->resolution)->toBeNull();
});

// ── Caso real del E2E (Contact 26): "sentadillas" pendiente, "Sentadillas
// sumo" no estaba entre las opciones mostradas pero resuelve sola. ──

it('resolves an unambiguous option that was never among the 5 shown, without concatenating candidate + response', function () {
    $exercise = Exercise::factory()->create(['name' => 'Sumo Squat', 'name_es' => 'Sentadillas sumo', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Banded Squat', 'name_es' => 'Sentadilla con banda', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Bodyweight Squat', 'name_es' => 'Sentadilla con peso corporal', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Bulgarian Squat', 'name_es' => 'Sentadilla búlgara con mancuernas', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Machine Squat', 'name_es' => 'Máquina de sentadilla con cinturón Cuads', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Smith Squat', 'name_es' => 'Sentadilla de glúteos en máquina Smith', 'is_active' => true]);

    $pending = pendingResolverFixture([
        'original_candidate_term' => 'sentadillas',
        'presented_options' => ['Sentadilla con banda', 'Sentadilla con peso corporal', 'Sentadilla búlgara con mancuernas', 'Máquina de sentadilla con cinturón Cuads', 'Sentadilla de glúteos en máquina Smith'],
        'total_matches' => 6,
    ]);

    $outcome = pendingResolver()->resolve($pending, 'Sentadillas sumo');

    expect($outcome->status)->toBe('resolved');
    expect($outcome->resolution->exerciseId)->toBe($exercise->id);
});

// ── Pureza: el candidato/opciones almacenados en la pending NUNCA influyen
// en el resultado — solo $responseBody y el catálogo real importan. ──

it('is pure: two pendings with different stored candidates produce the identical outcome for the same response', function () {
    Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);

    $pendingA = pendingResolverFixture(['original_candidate_term' => 'sentadillas']);
    $pendingB = pendingResolverFixture(['original_candidate_term' => 'un ejercicio totalmente distinto']);

    $outcomeA = pendingResolver()->resolve($pendingA, 'sentadilla');
    $outcomeB = pendingResolver()->resolve($pendingB, 'sentadilla');

    expect($outcomeA->status)->toBe($outcomeB->status)
        ->and($outcomeA->resolution->exerciseId)->toBe($outcomeB->resolution->exerciseId);
});

it('never writes to the database: resolving does not change the pending or create any TrainingPreference', function () {
    Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);
    $pending = pendingResolverFixture();

    pendingResolver()->resolve($pending, 'sentadilla');

    expect($pending->fresh()->status->value)->toBe('pending');
    expect(\App\Models\TrainingPreference::count())->toBe(0);
});
