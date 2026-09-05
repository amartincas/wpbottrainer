<?php

use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
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

// ── Bloque 5 (D048): health_screening_pending ──────────────────────────

it('14: denies the first routine when a DeclaredHealthCondition is pending review and the contact has no WorkoutSession yet', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]); // pending_review por defecto

    $result = (new TrainingAccessGate)->authorize($contact->fresh());

    expect($result->allowed)->toBeFalse();
    expect($result->reason)->toBe('health_screening_pending');
});

it('15: allows the first routine when there is no pending health declaration', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $result = (new TrainingAccessGate)->authorize($contact->fresh());

    expect($result->allowed)->toBeTrue();
});

it('does not block once the contact already has a WorkoutSession, even with a later pending declaration', function () {
    // Fuera de alcance de este bloque (solo "primera rutina") — una
    // declaración posterior a la primera sesión no revive este bloqueo.
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);

    $result = (new TrainingAccessGate)->authorize($contact->fresh());

    expect($result->allowed)->toBeTrue();
});

it('16: once resolved with a confirmed restriction, the gate allows continuing — SafetyRestrictionResolver applies it independently', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    DeclaredHealthCondition::factory()->resolvedNoRestriction()->create(['contact_id' => $contact->id]);

    $result = (new TrainingAccessGate)->authorize($contact->fresh());

    expect($result->allowed)->toBeTrue();
});

it('17: once resolved without a restriction, the gate allows continuing', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    DeclaredHealthCondition::factory()->resolvedNoRestriction()->create(['contact_id' => $contact->id]);

    $result = (new TrainingAccessGate)->authorize($contact->fresh());

    expect($result->allowed)->toBeTrue();
});

it('18: TrainingAccessGate never imports TrainingEngine — the bloqueo vive en la capa de orquestación, no en el motor', function () {
    // Se busca un `use` real, no la mención en prosa dentro del docblock que
    // explica esta misma garantía (mismo patrón de falso positivo ya visto
    // en otros tests de esta suite).
    $source = file_get_contents(app_path('Training/Support/TrainingAccessGate.php'));

    expect($source)->not->toMatch('/use [A-Za-z\\\\]*TrainingEngine;/');
});

it('a superseded declaration does not block — only pending_review does', function () {
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    DeclaredHealthCondition::factory()->superseded()->create(['contact_id' => $contact->id]);

    $result = (new TrainingAccessGate)->authorize($contact->fresh());

    expect($result->allowed)->toBeTrue();
});
