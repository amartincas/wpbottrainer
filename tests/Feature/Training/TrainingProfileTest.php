<?php

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\SafetyStatus;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;

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

it('only clears a safety flag via an explicit call, never automatically', function () {
    $profile = TrainingProfile::factory()->flaggedForSafetyReview('recent_surgery')->create();

    expect($profile->isFlaggedForSafetyReview())->toBeTrue();

    $profile->clearSafetyFlag();

    $fresh = $profile->fresh();
    expect($fresh->safety_status)->toBe(SafetyStatus::Normal);
    expect($fresh->safety_flag_reason)->toBeNull();
    expect($fresh->safety_flagged_at)->toBeNull();
});

it('does not carry an access_status field — entitlement lives in TrainingAccess', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('training_profiles', 'access_status'))->toBeFalse();
});
