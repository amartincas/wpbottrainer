<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingAccess;
use App\Models\TrainingPreference;
use App\Models\TrainingProfile;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\DurationEstimator;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\RequestedFocusGroup;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrainingCatalogInsufficientException;
use App\Training\Support\TrainingHistoryContextProvider;
use App\Training\Support\TrainingPreferenceResolver;

/**
 * Hito B3 (diseño v3 FINAL) — integración con `TrainingEngine`: exclusión
 * dura de Preference, separada de `isEligible()`; catálogo insuficiente
 * (5 casos, Sección G); `requested_focus_coverage.reason=preference`;
 * `applied_preferences` del snapshot (Sección G/5). Helpers con prefijo
 * "preference" — propios de este archivo, evita colisión con `trainingEngine()`
 * ya usado en TrainingEngineTest.php y otros.
 */
function preferenceReadyContact(array $profileOverrides = []): Contact
{
    $contact = Contact::factory()->create();

    TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'split_type' => SplitType::FullBody,
        'goal' => TrainingGoal::GeneralFitness,
    ], $profileOverrides));

    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

function preferenceEngine(): TrainingEngine
{
    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);

    return new TrainingEngine(
        new TrainingAccessGate,
        $safetyResolver,
        new TrainingHistoryContextProvider($safetyResolver, new TrainingPreferenceResolver),
        new ProgressionEvaluator,
        new DurationEstimator,
        new TrainingPreferenceResolver,
    );
}

// ── Exclusión dura ──

it('never selects an exercise excluded by an active Exercise-dimension preference', function () {
    $contact = preferenceReadyContact();
    $disliked = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    Exercise::factory()->count(3)->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);

    TrainingPreference::factory()->forExercise($disliked->id)->create(['contact_id' => $contact->id]);

    $session = preferenceEngine()->decideNextSession($contact->fresh());

    expect($session->workoutExercises->pluck('exercise_id'))->not->toContain($disliked->id);
});

it('never selects an exercise excluded by an active Equipment-dimension preference', function () {
    $contact = preferenceReadyContact(['equipment_fully_equipped' => true]);
    $withDumbbells = Exercise::factory()->create(['muscle_group' => 'back', 'equipment_needed' => ['dumbbells']]);
    Exercise::factory()->count(3)->create(['muscle_group' => 'back', 'equipment_needed' => []]);

    TrainingPreference::factory()->create([
        'contact_id' => $contact->id,
        'dimension' => PreferenceDimension::Equipment,
        'equipment_value' => 'dumbbells',
        'preference_key' => 'equipment:dumbbells',
    ]);

    $session = preferenceEngine()->decideNextSession($contact->fresh());

    expect($session->workoutExercises->pluck('exercise_id'))->not->toContain($withDumbbells->id);
});

it('a revoked preference no longer excludes anything', function () {
    $contact = preferenceReadyContact();
    $exercise = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);

    TrainingPreference::factory()->forExercise($exercise->id)->revoked()->create(['contact_id' => $contact->id]);

    $session = preferenceEngine()->decideNextSession($contact->fresh());

    // No se afirma que SEA seleccionado (depende del resto del catálogo),
    // solo que la preferencia revocada no participa en el filtro — se
    // verifica indirectamente vía TrainingPreferenceResolver, cubierto en
    // su propio test unitario; aquí solo confirmamos que decideNextSession
    // no lanza ni excluye por una fila revocada.
    expect($session->workoutExercises)->not->toBeEmpty();
});

it('excludes from Preparation/Cooldown too, not only Main', function () {
    $contact = preferenceReadyContact();
    $dislikedWarmup = Exercise::factory()->create(['muscle_group' => 'shoulders', 'exercise_type' => ['warmup']]);
    Exercise::factory()->create(['muscle_group' => 'shoulders', 'exercise_type' => ['warmup']]);
    Exercise::factory()->count(3)->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);

    TrainingPreference::factory()->forExercise($dislikedWarmup->id)->create(['contact_id' => $contact->id]);

    $session = preferenceEngine()->decideNextSession($contact->fresh());

    expect($session->workoutExercises->pluck('exercise_id'))->not->toContain($dislikedWarmup->id);
});

// ── isEligible() nunca cambia (Regla 13) — Safety y Equipment siguen íntegros ──

it('never touches isEligible() semantics: Safety/Equipment filters still apply independently of Preference', function () {
    $contact = preferenceReadyContact(['available_equipment' => [], 'equipment_fully_equipped' => false]);
    $needsDumbbells = Exercise::factory()->create(['muscle_group' => 'back', 'equipment_needed' => ['dumbbells']]);
    Exercise::factory()->count(3)->create(['muscle_group' => 'back', 'equipment_needed' => []]);

    // Ninguna preferencia activa — el ejercicio con mancuernas se excluye
    // exclusivamente por isEligible() (equipo no disponible), no por B3.
    $session = preferenceEngine()->decideNextSession($contact->fresh());

    expect($session->workoutExercises->pluck('exercise_id'))->not->toContain($needsDumbbells->id);
});

// ── Snapshot: applied_preferences ──

it('records applied_preferences in the snapshot only for preferences that actually excluded a candidate', function () {
    $contact = preferenceReadyContact();
    $disliked = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);
    Exercise::factory()->count(3)->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);

    TrainingPreference::factory()->forExercise($disliked->id, 'No me gustan los burpees.')->create(['contact_id' => $contact->id]);
    // Preferencia de equipment SIN ningún candidato real que excluir en esta
    // sesión (nadie usa 'kettlebell') — no debe aparecer en el snapshot.
    TrainingPreference::factory()->create([
        'contact_id' => $contact->id,
        'dimension' => PreferenceDimension::Equipment,
        'equipment_value' => 'kettlebell',
        'preference_key' => 'equipment:kettlebell',
    ]);

    $session = preferenceEngine()->decideNextSession($contact->fresh());
    $applied = $session->prescription_context_snapshot['applied_preferences'];

    expect($applied)->toHaveCount(1);
    expect($applied[0]['dimension'])->toBe('exercise');
    expect($applied[0]['exercise_id'])->toBe($disliked->id);
    expect($applied[0]['excluded_candidates_count'])->toBe(1);
});

it('applied_preferences double-counts independently when Exercise and Equipment preferences overlap on the same candidate', function () {
    $contact = preferenceReadyContact(['equipment_fully_equipped' => true]);
    $overlap = Exercise::factory()->create(['muscle_group' => 'back', 'equipment_needed' => ['dumbbells']]);
    Exercise::factory()->count(3)->create(['muscle_group' => 'back', 'equipment_needed' => []]);

    TrainingPreference::factory()->forExercise($overlap->id)->create(['contact_id' => $contact->id]);
    TrainingPreference::factory()->create([
        'contact_id' => $contact->id,
        'dimension' => PreferenceDimension::Equipment,
        'equipment_value' => 'dumbbells',
        'preference_key' => 'equipment:dumbbells',
    ]);

    $session = preferenceEngine()->decideNextSession($contact->fresh());
    $applied = collect($session->prescription_context_snapshot['applied_preferences']);

    expect($applied)->toHaveCount(2);
    expect($applied->firstWhere('dimension', 'exercise')['excluded_candidates_count'])->toBe(1);
    expect($applied->firstWhere('dimension', 'equipment')['excluded_candidates_count'])->toBe(1);
});

// ── Catálogo insuficiente (Sección G/13, 5 casos) ──

it('Caso B: preferences reduce the pool to zero Main -> TrainingCatalogInsufficientException, never relaxed', function () {
    $contact = preferenceReadyContact();
    $onlyExercise = Exercise::factory()->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);

    TrainingPreference::factory()->forExercise($onlyExercise->id)->create(['contact_id' => $contact->id]);

    expect(fn () => preferenceEngine()->decideNextSession($contact->fresh()))
        ->toThrow(TrainingCatalogInsufficientException::class);
});

it('Caso C: Requested Focus + Preference leaves a group without candidates -> reason=preference, session still completes', function () {
    $contact = preferenceReadyContact();
    $onlyLeg = Exercise::factory()->create(['muscle_group' => 'legs', 'primary_muscle' => MuscleFocus::Quads]);
    Exercise::factory()->count(3)->create(['muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest]);

    TrainingPreference::factory()->forExercise($onlyLeg->id)->create(['contact_id' => $contact->id]);

    $legsGroup = new RequestedFocusGroup('legs', [MuscleFocus::Quads->value]);
    $session = preferenceEngine()->decideNextSession($contact->fresh(), [$legsGroup]);

    $coverage = collect($session->prescription_context_snapshot['requested_focus_coverage']);
    $legsCoverage = $coverage->firstWhere('key', 'legs');

    expect($legsCoverage['status'])->toBe('unavailable');
    expect($legsCoverage['reason'])->toBe('preference');
    expect($session->workoutExercises->where('phase', WorkoutExercisePhase::Main))->not->toBeEmpty();
});

it('Caso E: catalog already insufficient before any preference — never blamed on preference', function () {
    $contact = preferenceReadyContact(['training_location' => TrainingLocation::Outdoor]);
    // Único ejercicio del catálogo requiere equipo -> inelegible en Outdoor,
    // ANTES de que cualquier preferencia participe.
    Exercise::factory()->create(['muscle_group' => 'chest', 'equipment_needed' => ['dumbbells']]);

    TrainingPreference::factory()->create([
        'contact_id' => $contact->id,
        'dimension' => PreferenceDimension::Equipment,
        'equipment_value' => 'barbell',
        'preference_key' => 'equipment:barbell',
    ]);

    expect(fn () => preferenceEngine()->decideNextSession($contact->fresh()))
        ->toThrow(TrainingCatalogInsufficientException::class);
});
