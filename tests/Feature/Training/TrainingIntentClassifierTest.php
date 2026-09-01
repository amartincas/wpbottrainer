<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Training\Support\TrainingIntentClassifier;

function makeClassifierContext(Tenant $tenant, ?string $body, string $from = '573001112233'): ExecutionContext
{
    return new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage($from, $body, 'wamid.1', 'text', null),
    );
}

it('classifies a message with training keywords as Intent::Training', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, 'Quiero empezar a entrenar')))->toBe(Intent::Training);
    expect($classifier->classify(makeClassifierContext($tenant, 'dame una rutina de gimnasio')))->toBe(Intent::Training);
});

it('declines (returns null) for unrelated messages with no training signal', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, 'Hola, ¿cómo estás?')))->toBeNull();
    expect($classifier->classify(makeClassifierContext($tenant, null)))->toBeNull();
});

it('keeps classifying as training for a contact mid-onboarding, even without keywords', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->incomplete()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingIntentClassifier;

    // Answering "3 veces por semana" has no training keyword at all.
    expect($classifier->classify(makeClassifierContext($tenant, '3 veces por semana', '573001112233')))->toBe(Intent::Training);
});

it('does not force training for a contact whose onboarding is already complete', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, 'Hola', '573001112233')))->toBeNull();
});

// ── hasActiveAccessAwaitingFirstWorkout (Hito 8.1) ──────────────────────

it('forces training for a contact with active access and zero WorkoutSessions, even with no keywords', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, 'algo sin ninguna palabra clave', '573001112233')))->toBe(Intent::Training);
});

it('classifies "Sí" as training right after activation, with no WorkoutSession yet', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, 'Sí', '573001112233')))->toBe(Intent::Training);
});

it('classifies "Dale" as training right after activation, with no WorkoutSession yet', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, 'Dale', '573001112233')))->toBe(Intent::Training);
});

it('does not apply the first-workout signal for a contact with no TrainingAccess at all', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    // Sin TrainingAccess.

    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, 'Sí', '573001112233')))->toBeNull();
});

it('does not apply the first-workout signal once a WorkoutSession already exists', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]); // ya tuvo al menos un entrenamiento

    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, 'Sí', '573001112233')))->toBeNull();
});

it('does not apply the first-workout signal when access is not Active (e.g. expired)', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->expired()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, 'Sí', '573001112233')))->toBeNull();
});
