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
