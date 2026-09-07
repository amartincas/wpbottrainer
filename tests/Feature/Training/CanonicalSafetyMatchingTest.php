<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\TrainingRestriction;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\BodyRegion;
use App\Training\Enums\SplitType;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrainingHistoryContextProvider;

/**
 * Hito de seguridad de restricciones — prueba explícita de la simetría de
 * canonicalización end-to-end (a través de TrainingEngine, no solo del
 * resolver aislado): TrainingProfile.restrictions + TrainingRestriction
 * (confirmed) del lado del usuario, Exercise.contraindications del lado
 * del ejercicio, ambos pasados por el MISMO BodyRegionCanonicalMapper.
 */
function canonicalTestEngine(): TrainingEngine
{
    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);

    return new TrainingEngine(
        new TrainingAccessGate,
        $safetyResolver,
        new TrainingHistoryContextProvider($safetyResolver),
        new ProgressionEvaluator,
    );
}

function readyContactWithRestrictions(array $restrictions): Contact
{
    $contact = Contact::factory()->create();

    TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'split_type' => SplitType::FullBody,
        'restrictions' => $restrictions,
    ]);

    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

it('Case A: user "lesión de hombro" and exercise "manguito rotador" both canonicalize to shoulder — exercise not eligible', function () {
    $contact = readyContactWithRestrictions(['lesión de hombro']);

    $restricted = Exercise::factory()->create(['muscle_group' => 'chest', 'contraindications' => ['manguito rotador']]);
    $safe = Exercise::factory()->create(['muscle_group' => 'chest', 'contraindications' => []]);

    $session = canonicalTestEngine()->decideNextSession($contact);
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->not->toContain($restricted->id);
    expect($selectedIds)->toContain($safe->id);
});

it('Case B: user "knee" and exercise "knee" (unrecognized, identical) remain literal — exercise not eligible, legacy preserved', function () {
    $contact = readyContactWithRestrictions(['knee']);

    $restricted = Exercise::factory()->create(['muscle_group' => 'legs', 'contraindications' => ['knee']]);
    $safe = Exercise::factory()->create(['muscle_group' => 'legs', 'contraindications' => []]);

    $session = canonicalTestEngine()->decideNextSession($contact);
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->not->toContain($restricted->id);
    expect($selectedIds)->toContain($safe->id);
});

it('Case C: user and exercise share the same unrecognized term — exercise not eligible', function () {
    $contact = readyContactWithRestrictions(['término completamente ajeno al catálogo']);

    $restricted = Exercise::factory()->create([
        'muscle_group' => 'back',
        'contraindications' => ['término completamente ajeno al catálogo'],
    ]);
    $safe = Exercise::factory()->create(['muscle_group' => 'back', 'contraindications' => []]);

    $session = canonicalTestEngine()->decideNextSession($contact);
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->not->toContain($restricted->id);
    expect($selectedIds)->toContain($safe->id);
});

it('Case D: user and exercise have different unrecognized terms — no intersection, exercise eligible (legacy-equivalent behavior)', function () {
    $contact = readyContactWithRestrictions(['término del usuario, nunca curado']);

    $unrelated = Exercise::factory()->create([
        'muscle_group' => 'shoulders',
        'contraindications' => ['término completamente distinto, tampoco curado'],
    ]);

    $session = canonicalTestEngine()->decideNextSession($contact);
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->toContain($unrelated->id);
});

it('Case E: a TrainingRestriction with status=pending_review does not participate in eligibility', function () {
    $contact = readyContactWithRestrictions([]);

    TrainingRestriction::factory()->pendingReview()->create([
        'contact_id' => $contact->id,
        'body_region' => BodyRegion::Shoulder,
    ]);

    $exercise = Exercise::factory()->create(['muscle_group' => 'chest', 'contraindications' => ['lesión de hombro']]);

    $session = canonicalTestEngine()->decideNextSession($contact);

    expect($session->workoutExercises->pluck('exercise_id')->all())->toContain($exercise->id);
});

it('Case F: a TrainingRestriction with status=confirmed does participate in eligibility', function () {
    $contact = readyContactWithRestrictions([]);

    TrainingRestriction::factory()->create([
        'contact_id' => $contact->id,
        'body_region' => BodyRegion::Shoulder,
    ]);

    $restricted = Exercise::factory()->create(['muscle_group' => 'chest', 'contraindications' => ['lesión de hombro']]);
    $safe = Exercise::factory()->create(['muscle_group' => 'chest', 'contraindications' => []]);

    $session = canonicalTestEngine()->decideNextSession($contact);
    $selectedIds = $session->workoutExercises->pluck('exercise_id')->all();

    expect($selectedIds)->not->toContain($restricted->id);
    expect($selectedIds)->toContain($safe->id);
});
