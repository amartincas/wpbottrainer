<?php

use App\Models\Contact;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Training\Support\TrainingAccessGate;

it('denies access when the contact has no TrainingAccess row at all', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $result = (new TrainingAccessGate)->authorize($contact->fresh());

    expect($result->allowed)->toBeFalse();
    expect($result->reason)->toBe('no_access');
});

it('denies access when TrainingAccess exists but is not currently valid', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->expired()->create(['contact_id' => $contact->id]);

    $result = (new TrainingAccessGate)->authorize($contact->fresh());

    expect($result->allowed)->toBeFalse();
    expect($result->reason)->toBe('access_invalid');
});

it('denies access when the profile is flagged for safety review, even with valid access', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->flaggedForSafetyReview()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $result = (new TrainingAccessGate)->authorize($contact->fresh());

    expect($result->allowed)->toBeFalse();
    expect($result->reason)->toBe('safety_flagged');
});

it('allows access when the profile is normal and TrainingAccess is currently valid', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $result = (new TrainingAccessGate)->authorize($contact->fresh());

    expect($result->allowed)->toBeTrue();
    expect($result->reason)->toBeNull();
});

it('never allows access to be granted by anything other than an explicit TrainingAccess row', function () {
    // No mecanismo alternativo (flags en Contact, config global, etc.) debe
    // poder otorgar acceso — la única fuente de verdad es TrainingAccess.
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $result = (new TrainingAccessGate)->authorize($contact->fresh());

    expect($result->allowed)->toBeFalse();
});
