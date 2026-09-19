<?php

use App\Core\Messaging\ContextualIntentClassifierInterface;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Training\Support\TrainingContextualIntentClassifier;

/**
 * Precedencia de Intents (ver docs/DECISIONS.md) — extraído literalmente de
 * TrainingIntentClassifierTest.php: estos tests prueban la mitad
 * CONTEXTUAL de la clasificación de Training (antes vivía en la misma clase
 * que las keywords explícitas). Ninguna condición ni aserción cambió de
 * significado — solo la clase bajo prueba.
 */
function makeContextualClassifierContext(Tenant $tenant, ?string $body, string $from = '573001112233'): ExecutionContext
{
    return new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage($from, $body, 'wamid.1', 'text', null),
    );
}

it('implements the contextual marker interface', function () {
    expect(new TrainingContextualIntentClassifier)->toBeInstanceOf(ContextualIntentClassifierInterface::class);
});

it('keeps classifying as training for a contact mid-onboarding, even without keywords', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->incomplete()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingContextualIntentClassifier;

    // Answering "3 veces por semana" has no training keyword at all.
    expect($classifier->classify(makeContextualClassifierContext($tenant, '3 veces por semana', '573001112233')))->toBe(Intent::Training);
});

it('does not force training for a contact whose onboarding is already complete', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'Hola', '573001112233')))->toBeNull();
});

it('declines for a contact that does not exist at all', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'algo cualquiera', '573009998877')))->toBeNull();
});

// ── hasActiveAccessAwaitingFirstWorkout (Hito 8.1) ──────────────────────

it('forces training for a contact with active access and zero WorkoutSessions, even with no keywords', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'algo sin ninguna palabra clave', '573001112233')))->toBe(Intent::Training);
});

it('classifies "Sí" as training right after activation, with no WorkoutSession yet', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'Sí', '573001112233')))->toBe(Intent::Training);
});

it('classifies "Dale" as training right after activation, with no WorkoutSession yet', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'Dale', '573001112233')))->toBe(Intent::Training);
});

it('does not apply the first-workout signal for a contact with no TrainingAccess at all', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    // Sin TrainingAccess.

    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'Sí', '573001112233')))->toBeNull();
});

it('does not apply the first-workout signal once a WorkoutSession already exists', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]); // ya tuvo al menos un entrenamiento

    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'Sí', '573001112233')))->toBeNull();
});

it('does not apply the first-workout signal when access is not Active (e.g. expired)', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->expired()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'Sí', '573001112233')))->toBeNull();
});

// ── hasPendingWorkoutSession (Hito 6) ───────────────────────────────────

it('forces training for a contact with a pending (Scheduled) WorkoutSession, even with no keywords', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->create(['contact_id' => $contact->id]); // default: Scheduled

    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'Sentadilla 10x40', '573001112233')))->toBe(Intent::Training);
});

it('does not force training once the only WorkoutSession is already completed', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'Hola', '573001112233')))->toBeNull();
});

// ── hasAwaitingReminderResponse (Hito 10 / D053) ────────────────────────

it('forces training for a contact awaiting a response to a just-fired Reminder, even with no keywords', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    \App\Models\Reminder::factory()->awaitingResponse()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'Dale', '573001112233')))->toBe(Intent::Training);
});

it('does not force training once the Reminder awaiting-response window already closed', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    \App\Models\Reminder::factory()->awaitingResponse()->create([
        'contact_id' => $contact->id,
        'awaiting_response_until' => now()->subMinutes(5),
    ]);

    $classifier = new TrainingContextualIntentClassifier;

    expect($classifier->classify(makeContextualClassifierContext($tenant, 'Hola', '573001112233')))->toBeNull();
});
