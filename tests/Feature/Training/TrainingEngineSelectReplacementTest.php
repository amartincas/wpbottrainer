<?php

use App\Models\Exercise;
use App\Models\TrainingPreference;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\PreferenceStatus;
use App\Training\Enums\TrainingLocation;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\RequestedFocusGroup;
use App\Training\Support\TrainingCatalogInsufficientException;

/**
 * Hito C (Sustitución de un ejercicio) — `TrainingEngine::selectReplacement()`.
 * Reutiliza `makeReadyContact()`/`trainingEngine()` de `TrainingEngineTest.php`
 * (mismo directorio, mismo criterio ya establecido por `TrainingEngineVarietyTest`
 * de compartir estos helpers entre archivos del motor).
 *
 * IMPORTANTE — `WorkoutExercise::factory()->create()` NO se usa aquí: su
 * `definition()` real crea SIEMPRE un `Exercise` adicional como efecto
 * secundario (`$exercise = Exercise::factory()->create();`), incluso cuando
 * `exercise_id` se sobreescribe — contaminaría el pool elegible de forma
 * impredecible en estos tests, que necesitan control exacto del catálogo.
 * Se usa `WorkoutExercise::create()` directamente, con `exercise_snapshot`
 * explícito, sin ningún efecto secundario.
 *
 * REGLA ABSOLUTA verificada explícitamente aquí: `selectReplacement()` NUNCA
 * persiste — se verifica el conteo de `WorkoutExercise` ANTES y DESPUÉS de
 * cada llamada.
 */
function selectReplacementSession(array $sessionOverrides = []): WorkoutSession
{
    return WorkoutSession::factory()->create(array_merge([
        'status' => WorkoutSessionStatus::Scheduled,
    ], $sessionOverrides));
}

function selectReplacementTarget(WorkoutSession $session, Exercise $exercise, int $order, WorkoutExercisePhase $phase): WorkoutExercise
{
    return WorkoutExercise::create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'order' => $order,
        'phase' => $phase,
        'prescribed_sets' => $phase === WorkoutExercisePhase::Main ? 3 : 1,
        'prescribed_reps' => $phase === WorkoutExercisePhase::Main ? 10 : null,
        'prescribed_load' => null,
        'prescribed_duration_seconds' => $phase === WorkoutExercisePhase::Main ? null : 90,
        'rest_seconds' => $phase === WorkoutExercisePhase::Main ? 60 : 0,
        'exercise_snapshot' => $exercise->toSnapshot(),
    ]);
}

it('selects an eligible replacement for a Main target, never itself', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest', 'is_active' => true]);
    $candidate = Exercise::factory()->create(['muscle_group' => 'chest', 'is_active' => true]);
    $target = selectReplacementTarget($session, $original, 2, WorkoutExercisePhase::Main);

    $countBefore = WorkoutExercise::count();

    $attributes = trainingEngine()->selectReplacement($contact, $target);

    expect(WorkoutExercise::count())->toBe($countBefore); // nunca persiste
    expect($attributes['exercise_id'])->toBe($candidate->id);
    expect($attributes['exercise_id'])->not->toBe($original->id);
});

it('excludes exercises already present elsewhere in the same session (Preparation+Main+Cooldown)', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest']);
    $target = selectReplacementTarget($session, $original, 2, WorkoutExercisePhase::Main);

    $alreadyInPrep = Exercise::factory()->create(['muscle_group' => 'chest', 'exercise_type' => ['warmup']]);
    selectReplacementTarget($session, $alreadyInPrep, 1, WorkoutExercisePhase::Preparation);

    $onlyRemainingCandidate = Exercise::factory()->create(['muscle_group' => 'chest']);

    $attributes = trainingEngine()->selectReplacement($contact, $target->fresh());

    expect($attributes['exercise_id'])->toBe($onlyRemainingCandidate->id);
    expect($attributes['exercise_id'])->not->toBe($alreadyInPrep->id);
});

// ============================================================
// C-REUSE (fix post-deploy, auditoría de reutilización intra-sesión,
// E2E real) — un exercise_id que ya apareció en CUALQUIER WorkoutExercise
// de esta sesión (activo o superseded) no puede volver a ser seleccionado
// como replacement, sin importar cuántas sustituciones ya ocurrieron.
// ============================================================

it('C-REUSE-1: an exercise superseded earlier in the SAME session cannot be selected again', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $target = selectReplacementTarget($session, Exercise::factory()->create(['muscle_group' => 'chest']), 3, WorkoutExercisePhase::Main);

    // Ejercicio A: ya apareció en esta sesión y fue sustituido — su fila
    // original queda con superseded_by_id poblado (simula una sustitución
    // previa ya ocurrida en la misma sesión, ej. "Bisagra->Sentadilla" del
    // caso real de E2E).
    $exerciseA = Exercise::factory()->create(['muscle_group' => 'chest']);
    $originalA = selectReplacementTarget($session, $exerciseA, 1, WorkoutExercisePhase::Main);
    $originalA->update(['superseded_by_id' => $target->id]);

    $exerciseB = Exercise::factory()->create(['muscle_group' => 'chest']);

    $attributes = trainingEngine()->selectReplacement($contact, $target->fresh());

    expect($attributes['exercise_id'])->toBe($exerciseB->id);
    expect($attributes['exercise_id'])->not->toBe($exerciseA->id);
});

it('C-REUSE-2: an exercise_id never used in the session can still be selected, even alongside a superseded one', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $target = selectReplacementTarget($session, Exercise::factory()->create(['muscle_group' => 'back']), 1, WorkoutExercisePhase::Main);

    $exerciseA = Exercise::factory()->create(['muscle_group' => 'back']);
    $originalA = selectReplacementTarget($session, $exerciseA, 2, WorkoutExercisePhase::Main);
    $originalA->update(['superseded_by_id' => $target->id]);

    $neverUsed = Exercise::factory()->create(['muscle_group' => 'back']);

    $attributes = trainingEngine()->selectReplacement($contact, $target->fresh());

    expect($attributes['exercise_id'])->toBe($neverUsed->id);
});

it('C-REUSE-3: when the only eligible candidate already appeared (superseded) in the session, throws TrainingCatalogInsufficientException', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $target = selectReplacementTarget($session, Exercise::factory()->create(['muscle_group' => 'arms']), 1, WorkoutExercisePhase::Main);

    $exerciseA = Exercise::factory()->create(['muscle_group' => 'arms']);
    $originalA = selectReplacementTarget($session, $exerciseA, 2, WorkoutExercisePhase::Main);
    $originalA->update(['superseded_by_id' => $target->id]);
    // Sin ningún otro Exercise 'arms' en el catálogo — el único candidato
    // real ya apareció (superseded) en esta misma sesión. Misma excepción
    // que ya usa selectReplacement() para catálogo insuficiente, ninguna
    // excepción nueva.

    expect(fn () => trainingEngine()->selectReplacement($contact, $target->fresh()))
        ->toThrow(TrainingCatalogInsufficientException::class);
});

it('C-REUSE-4: the exclusion is scoped strictly per session — an exercise superseded in session A remains a valid candidate for a NEW session B', function () {
    // Location/equipment fijados explícitamente (nunca el default aleatorio
    // de TrainingProfileFactory): el "filler" de cada sesión exige equipo
    // que el perfil no tiene, así queda ineligible por SÍ MISMO (isEligible),
    // sin depender de ninguna exclusión por sesión — aísla la variable real
    // bajo prueba (el scope de la exclusión histórica), evitando que
    // compita por id con exerciseA como candidato de la sesión B.
    $contact = makeReadyContact([
        'training_location' => TrainingLocation::Home,
        'equipment_fully_equipped' => false,
        'available_equipment' => [],
    ]);

    $sessionA = selectReplacementSession(['contact_id' => $contact->id]);
    $fillerA = Exercise::factory()->create(['muscle_group' => 'shoulders', 'equipment_needed' => ['dumbbells']]);
    $targetA = selectReplacementTarget($sessionA, $fillerA, 1, WorkoutExercisePhase::Main);
    $exerciseA = Exercise::factory()->create(['muscle_group' => 'shoulders', 'equipment_needed' => []]);
    $originalA = selectReplacementTarget($sessionA, $exerciseA, 2, WorkoutExercisePhase::Main);
    $originalA->update(['superseded_by_id' => $targetA->id]);

    // Sesión B: distinta e independiente — exerciseA NUNCA se usó aquí.
    // Único candidato REALMENTE elegible ('shoulders', sin equipo) en todo
    // el catálogo.
    $sessionB = selectReplacementSession(['contact_id' => $contact->id]);
    $fillerB = Exercise::factory()->create(['muscle_group' => 'shoulders', 'equipment_needed' => ['dumbbells']]);
    $targetB = selectReplacementTarget($sessionB, $fillerB, 1, WorkoutExercisePhase::Main);

    $attributes = trainingEngine()->selectReplacement($contact, $targetB->fresh());

    expect($attributes['exercise_id'])->toBe($exerciseA->id);
});

it('C-REUSE-5: the new intra-session exclusion still lets requestedFocus and an active TrainingPreference apply normally on top of it', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $target = selectReplacementTarget($session, Exercise::factory()->create(['muscle_group' => 'chest']), 1, WorkoutExercisePhase::Main);

    // Ya usado (superseded) en esta sesión Y coincide con el foco pedido —
    // debe seguir excluido pese a matchear el foco.
    $usedLegsExercise = Exercise::factory()->create(['muscle_group' => 'legs', 'primary_muscle' => MuscleFocus::Quads]);
    $originalUsed = selectReplacementTarget($session, $usedLegsExercise, 2, WorkoutExercisePhase::Main);
    $originalUsed->update(['superseded_by_id' => $target->id]);

    // Preferencia B3 activa contra un segundo candidato de foco.
    $dislikedLegsExercise = Exercise::factory()->create(['muscle_group' => 'legs', 'primary_muscle' => MuscleFocus::Quads]);
    TrainingPreference::create([
        'contact_id' => $contact->id,
        'dimension' => PreferenceDimension::Exercise,
        'exercise_id' => $dislikedLegsExercise->id,
        'preference_key' => "exercise:{$dislikedLegsExercise->id}",
        'status' => PreferenceStatus::Active,
        'original_text' => 'No me gusta.',
        'created_at' => now(),
    ]);

    // Único candidato legítimo restante: nunca usado en la sesión, sin
    // preferencia activa, coincide con el foco.
    $legitimateCandidate = Exercise::factory()->create(['muscle_group' => 'legs', 'primary_muscle' => MuscleFocus::Quads]);

    $requestedFocus = new RequestedFocusGroup('legs', [MuscleFocus::Quads->value]);
    $attributes = trainingEngine()->selectReplacement($contact, $target->fresh(), $requestedFocus);

    expect($attributes['exercise_id'])->toBe($legitimateCandidate->id);
});

it('excludes exercises matching an active TrainingPreference', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'back']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);

    $disliked = Exercise::factory()->create(['muscle_group' => 'back']);
    TrainingPreference::create([
        'contact_id' => $contact->id,
        'dimension' => PreferenceDimension::Exercise,
        'exercise_id' => $disliked->id,
        'preference_key' => "exercise:{$disliked->id}",
        'status' => PreferenceStatus::Active,
        'original_text' => 'No me gusta.',
        'created_at' => now(),
    ]);
    $allowed = Exercise::factory()->create(['muscle_group' => 'back']);

    $attributes = trainingEngine()->selectReplacement($contact, $target->fresh());

    expect($attributes['exercise_id'])->toBe($allowed->id);
});

it('excludes exercises blocked by eligibility (Outdoor + equipment required)', function () {
    $contact = makeReadyContact(['training_location' => TrainingLocation::Outdoor]);
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'legs']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);

    $needsEquipment = Exercise::factory()->create(['muscle_group' => 'legs', 'equipment_needed' => ['dumbbells']]);
    $noEquipment = Exercise::factory()->create(['muscle_group' => 'legs', 'equipment_needed' => []]);

    $attributes = trainingEngine()->selectReplacement($contact, $target->fresh());

    expect($attributes['exercise_id'])->toBe($noEquipment->id);
    expect($attributes['exercise_id'])->not->toBe($needsEquipment->id);
});

it('respects a requested focus when it has a real candidate', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);

    $generalCandidate = Exercise::factory()->create(['muscle_group' => 'chest']);
    $legsCandidate = Exercise::factory()->create(['muscle_group' => 'legs', 'primary_muscle' => MuscleFocus::Quads]);

    $requestedFocus = new RequestedFocusGroup('legs', [MuscleFocus::Quads->value]);
    $attributes = trainingEngine()->selectReplacement($contact, $target->fresh(), $requestedFocus);

    expect($attributes['exercise_id'])->toBe($legsCandidate->id);
    expect($attributes['exercise_id'])->not->toBe($generalCandidate->id);
});

it('falls back to the general pool when the requested focus has no candidate at all', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);

    $onlyRealCandidate = Exercise::factory()->create(['muscle_group' => 'chest']);
    // Ningún ejercicio elegible existe con MuscleFocus::Back — el foco pedido es insatisfacible.
    $requestedFocus = new RequestedFocusGroup('back', [MuscleFocus::Back->value]);

    $attributes = trainingEngine()->selectReplacement($contact, $target->fresh(), $requestedFocus);

    expect($attributes['exercise_id'])->toBe($onlyRealCandidate->id);
});

it('throws TrainingCatalogInsufficientException when no candidate survives all exclusions', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $onlyExercise = Exercise::factory()->create(['muscle_group' => 'arms']);
    $target = selectReplacementTarget($session, $onlyExercise, 1, WorkoutExercisePhase::Main);
    // Sin ningún otro Exercise activo en el catálogo: el único candidato
    // real es el propio target, excluido estructuralmente.

    expect(fn () => trainingEngine()->selectReplacement($contact, $target->fresh()))
        ->toThrow(TrainingCatalogInsufficientException::class);
});

it('Main target: returns progression-based prescription attributes (insufficient_data -> GOAL_DEFAULTS)', function () {
    $contact = makeReadyContact(); // goal=general_fitness -> sets=3, reps=10 (GOAL_DEFAULTS)
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'shoulders']);
    $target = selectReplacementTarget($session, $original, 3, WorkoutExercisePhase::Main);
    Exercise::factory()->create(['muscle_group' => 'shoulders']);

    $attributes = trainingEngine()->selectReplacement($contact, $target->fresh());

    expect($attributes['phase'])->toBe(WorkoutExercisePhase::Main);
    expect($attributes['order'])->toBe(3); // heredado del target, nunca cambia
    expect($attributes['prescribed_sets'])->toBe(3);
    expect($attributes['prescribed_reps'])->toBe(10);
    expect($attributes['exercise_snapshot'])->toBeArray();
    expect($attributes)->not->toHaveKey('workout_session_id'); // lo añade el Service, no TrainingEngine
});

it('Preparation target: returns the fixed support prescription, same phase and order', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['exercise_type' => ['warmup']]);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Preparation);
    Exercise::factory()->create(['exercise_type' => ['warmup']]);

    $attributes = trainingEngine()->selectReplacement($contact, $target->fresh());

    expect($attributes['phase'])->toBe(WorkoutExercisePhase::Preparation);
    expect($attributes['order'])->toBe(1);
    expect($attributes['prescribed_sets'])->toBe(1);
    expect($attributes['prescribed_reps'])->toBeNull();
    expect($attributes['prescribed_duration_seconds'])->toBe(90);
    expect($attributes['rest_seconds'])->toBe(0);
});

it('Cooldown target: returns the fixed support prescription, same phase and order', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['exercise_type' => ['cooldown']]);
    $target = selectReplacementTarget($session, $original, 4, WorkoutExercisePhase::Cooldown);
    Exercise::factory()->create(['exercise_type' => ['cooldown']]);

    $attributes = trainingEngine()->selectReplacement($contact, $target->fresh());

    expect($attributes['phase'])->toBe(WorkoutExercisePhase::Cooldown);
    expect($attributes['order'])->toBe(4);
    expect($attributes['prescribed_duration_seconds'])->toBe(90);
});
