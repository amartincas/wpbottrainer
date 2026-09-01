<?php

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\SafetyStatus;
use App\Training\Enums\Sex;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;

it('belongs to a contact', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    expect($profile->contact->is($contact))->toBeTrue();
    expect($contact->fresh()->trainingProfile->is($profile))->toBeTrue();
});

it('casts enum and array fields correctly', function () {
    $profile = TrainingProfile::factory()->create([
        'goal' => TrainingGoal::BuildMuscle,
        'experience_level' => ExperienceLevel::Beginner,
        'split_type' => SplitType::UpperLower,
        'available_equipment' => ['dumbbells', 'bands'],
        'restrictions' => ['knee'],
    ]);

    $fresh = $profile->fresh();

    expect($fresh->goal)->toBe(TrainingGoal::BuildMuscle);
    expect($fresh->experience_level)->toBe(ExperienceLevel::Beginner);
    expect($fresh->split_type)->toBe(SplitType::UpperLower);
    expect($fresh->available_equipment)->toBe(['dumbbells', 'bands']);
    expect($fresh->restrictions)->toBe(['knee']);
});

it('flags a profile for safety review deterministically', function () {
    $profile = TrainingProfile::factory()->create(['safety_status' => SafetyStatus::Normal]);

    $profile->flagForSafetyReview('chest_pain');

    $fresh = $profile->fresh();
    expect($fresh->safety_status)->toBe(SafetyStatus::FlaggedForReview);
    expect($fresh->safety_flag_reason)->toBe('chest_pain');
    expect($fresh->safety_flagged_at)->not->toBeNull();
    expect($fresh->isFlaggedForSafetyReview())->toBeTrue();
});

it('only clears a safety flag via an explicit human review, never automatically', function () {
    $profile = TrainingProfile::factory()->flaggedForSafetyReview('recent_surgery')->create();
    $reviewer = \App\Models\User::factory()->create();

    expect($profile->isFlaggedForSafetyReview())->toBeTrue();

    $profile->clearSafetyFlag($reviewer, 'Consultó con su médico, autorizado a continuar.');

    $fresh = $profile->fresh();
    expect($fresh->safety_status)->toBe(SafetyStatus::Normal);
    expect($fresh->safety_flag_reason)->toBeNull();
    expect($fresh->safety_flagged_at)->toBeNull();
    expect($fresh->safety_reviewed_by)->toBe($reviewer->id);
    expect($fresh->safety_reviewed_at)->not->toBeNull();
    expect($fresh->safety_review_note)->toBe('Consultó con su médico, autorizado a continuar.');
});

it('resets any previous safety review when flagged again — a new incident invalidates the old review', function () {
    $reviewer = \App\Models\User::factory()->create();
    $profile = TrainingProfile::factory()->create(['safety_status' => SafetyStatus::Normal]);
    $profile->clearSafetyFlag($reviewer, 'Ya revisado una vez.');

    expect($profile->fresh()->safety_reviewed_by)->toBe($reviewer->id);

    $profile->flagForSafetyReview('chest_pain');

    $fresh = $profile->fresh();
    expect($fresh->safety_status)->toBe(SafetyStatus::FlaggedForReview);
    expect($fresh->safety_reviewed_by)->toBeNull();
    expect($fresh->safety_reviewed_at)->toBeNull();
    expect($fresh->safety_review_note)->toBeNull();
});

it('does not carry an access_status field — entitlement lives in TrainingAccess', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('training_profiles', 'access_status'))->toBeFalse();
});

// ── Hito 8.3: campos de perfil ampliados ────────────────────────────────

it('casts the new Hito 8.3 profile fields correctly', function () {
    $profile = TrainingProfile::factory()->create([
        'training_location' => TrainingLocation::Gym,
        'equipment_fully_equipped' => true,
        'age' => 30,
        'sex' => Sex::Female,
        'weight_kg' => 65.5,
        'height_cm' => 168,
        'physical_stats_asked' => true,
    ]);

    $fresh = $profile->fresh();

    expect($fresh->training_location)->toBe(TrainingLocation::Gym);
    expect($fresh->equipment_fully_equipped)->toBeTrue();
    expect($fresh->age)->toBe(30);
    expect($fresh->sex)->toBe(Sex::Female);
    expect((float) $fresh->weight_kg)->toBe(65.5);
    expect($fresh->height_cm)->toBe(168);
    expect($fresh->physical_stats_asked)->toBeTrue();
});

it('requires training_location to consider onboarding complete, but never age/sex/weight/height', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'training_location' => null, // todo lo demás completo salvo esto
    ]);

    expect($profile->isOnboardingComplete($contact))->toBeFalse();
    expect($profile->firstMissingOnboardingField($contact))->toBe('training_location');

    $profile->update(['training_location' => TrainingLocation::Home]);

    // Sin edad/sexo/peso/estatura, y sin embargo completo — porque
    // physical_stats_asked ya es true por defecto en la factory (Hito 8.3:
    // estos 4 datos nunca bloquean, ver docs/DECISIONS.md).
    expect($profile->fresh()->isOnboardingComplete($contact))->toBeTrue();
});

it('requires the contact name before any TrainingProfile field, and requires asking physical stats once before considering onboarding complete', function () {
    $contact = Contact::factory()->create(['customer_name' => null]);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    expect($profile->firstMissingOnboardingField($contact))->toBe('name');

    $contact->update(['customer_name' => 'Laura']);
    expect($profile->fresh()->firstMissingOnboardingField($contact->fresh()))->toBeNull();

    // Si nunca se preguntaron los datos físicos, el onboarding queda
    // pendiente exactamente en ese único punto — nunca por los datos en sí.
    $profile->update(['physical_stats_asked' => false]);
    expect($profile->fresh()->firstMissingOnboardingField($contact->fresh()))->toBe('physical_stats');
});

// ── Hito 8.4: objetivos específicos (primary_focus/secondary_focus) ────

it('casts primary_focus and secondary_focus as arrays', function () {
    $profile = TrainingProfile::factory()->create([
        'primary_focus' => ['glutes', 'quads'],
        'secondary_focus' => ['back'],
    ]);

    $fresh = $profile->fresh();

    expect($fresh->primary_focus)->toBe(['glutes', 'quads']);
    expect($fresh->secondary_focus)->toBe(['back']);
});

it('requires primary_focus to consider onboarding complete, distinguishing "not asked yet" (null) from "asked, no preference" ([])', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'primary_focus' => null,
    ]);

    expect($profile->isOnboardingComplete($contact))->toBeFalse();
    expect($profile->firstMissingOnboardingField($contact))->toBe('primary_focus');

    $profile->update(['primary_focus' => []]);

    expect($profile->fresh()->isOnboardingComplete($contact))->toBeTrue();
});

it('asks for primary_focus right after experience_level, before training_location', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'primary_focus' => null,
        'training_location' => null,
    ]);

    // Aunque training_location también falta, primary_focus tiene prioridad
    // — el orden aprobado es nombre → objetivo → nivel → zona a priorizar →
    // lugar → equipamiento → restricciones → frecuencia → datos físicos.
    expect($profile->firstMissingOnboardingField($contact))->toBe('primary_focus');
});

it('never lets secondary_focus block onboarding, even when null', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'secondary_focus' => null,
    ]);

    expect($profile->isOnboardingComplete($contact))->toBeTrue();
});
