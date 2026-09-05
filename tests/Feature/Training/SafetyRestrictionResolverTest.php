<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingProfile;
use App\Models\TrainingRestriction;
use App\Training\Enums\BodyRegion;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\SafetyRestrictionResolver;
use Illuminate\Support\Facades\Log;

function safetyResolver(): SafetyRestrictionResolver
{
    return new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);
}

function profileWithRestrictions(array $restrictions): TrainingProfile
{
    $contact = Contact::factory()->create();

    return TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'restrictions' => $restrictions,
    ]);
}

it('a TrainingRestriction with status=confirmed contributes its BodyRegion to the active set', function () {
    $profile = profileWithRestrictions([]);
    // El estado por defecto del factory ya es status=Confirmed.
    TrainingRestriction::factory()->create([
        'contact_id' => $profile->contact_id,
        'body_region' => BodyRegion::Knee,
    ]);

    $regions = safetyResolver()->activeSafetyBodyRegions($profile);

    expect($regions)->toContain('knee');
});

it('a TrainingRestriction with status=pending_review never affects eligibility', function () {
    $profile = profileWithRestrictions([]);
    TrainingRestriction::factory()->pendingReview()->create([
        'contact_id' => $profile->contact_id,
        'body_region' => BodyRegion::Shoulder,
    ]);

    $regions = safetyResolver()->activeSafetyBodyRegions($profile);

    expect($regions)->toBe([]);
});

it('a TrainingRestriction with status=resolved never affects eligibility', function () {
    $profile = profileWithRestrictions([]);
    TrainingRestriction::factory()->resolved()->create([
        'contact_id' => $profile->contact_id,
        'body_region' => BodyRegion::Wrist,
    ]);

    expect(safetyResolver()->activeSafetyBodyRegions($profile))->toBe([]);
});

it('a TrainingRestriction with status=superseded never affects eligibility', function () {
    $profile = profileWithRestrictions([]);
    TrainingRestriction::factory()->superseded()->create([
        'contact_id' => $profile->contact_id,
        'body_region' => BodyRegion::Elbow,
    ]);

    expect(safetyResolver()->activeSafetyBodyRegions($profile))->toBe([]);
});

it('normalizes a legacy free-text restriction that matches one of the 8 real known values', function () {
    $profile = profileWithRestrictions(['manguito rotador']);

    expect(safetyResolver()->activeSafetyBodyRegions($profile))->toBe(['shoulder']);
});

it('preserves an unrecognized legacy restriction as an exact-match tag instead of dropping it — no regression', function () {
    // Prueba directa de la garantía de no-regresión: un valor legacy que
    // no está en el diccionario de los 8 reales (ej. un valor de prueba
    // arbitrario, o cualquier texto futuro no curado todavía) NUNCA se
    // descarta — se preserva tal cual para que la comparación exacta que
    // ya funcionaba hoy siga funcionando exactamente igual.
    $profile = profileWithRestrictions(['knee']);

    expect(safetyResolver()->activeSafetyBodyRegions($profile))->toBe(['knee']);
});

it('logs unrecognized legacy text without inferring any region from it', function () {
    Log::spy();

    $profile = profileWithRestrictions(['un texto completamente nuevo, nunca antes visto']);

    $regions = safetyResolver()->activeSafetyBodyRegions($profile);

    expect($regions)->toBe(['un texto completamente nuevo, nunca antes visto']);
    Log::shouldHaveReceived('info')->with('LEGACY_RESTRICTION_UNRECOGNIZED', Mockery::on(
        fn ($context) => $context['text'] === 'un texto completamente nuevo, nunca antes visto'
    ))->once();
});

it('combines confirmed structured restrictions and legacy text without duplicating an already-covered region', function () {
    $profile = profileWithRestrictions(['lesión de hombro']); // legacy -> shoulder
    TrainingRestriction::factory()->create([
        'contact_id' => $profile->contact_id,
        'body_region' => BodyRegion::Shoulder, // mismo region que el legacy
    ]);
    TrainingRestriction::factory()->create([
        'contact_id' => $profile->contact_id,
        'body_region' => BodyRegion::Knee,
    ]);

    $regions = safetyResolver()->activeSafetyBodyRegions($profile);

    expect($regions)->toEqualCanonicalizing(['shoulder', 'knee']);
});

it('exerciseBodyRegions() applies the exact same exact-match dictionary to Exercise.contraindications', function () {
    $exercise = Exercise::factory()->create(['contraindications' => ['hernia discal', 'texto sin reconocer']]);

    $regions = safetyResolver()->exerciseBodyRegions($exercise);

    expect($regions)->toEqualCanonicalizing(['lower_back', 'texto sin reconocer']);
});

it('demonstrates the full non-regression case: a real production exercise (Barbell Curls) keeps excluding wrist/elbow restrictions', function () {
    $exercise = Exercise::factory()->create(['contraindications' => ['lesión de muñeca', 'lesión de codo']]);
    $profile = profileWithRestrictions(['lesión de codo']);

    $resolver = safetyResolver();
    $activeRestrictions = $resolver->activeSafetyBodyRegions($profile);
    $exerciseTags = $resolver->exerciseBodyRegions($exercise);

    expect(array_intersect($activeRestrictions, $exerciseTags))->not->toBe([]);
});
