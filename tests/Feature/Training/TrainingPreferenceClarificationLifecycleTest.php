<?php

use App\Models\Contact;
use App\Models\TrainingPreferenceClarification;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\TrainingPreferenceClarificationStatus;
use App\Training\Support\TrainingPreferenceClarificationRecorder;
use Illuminate\Database\QueryException;

/**
 * Hito B3.1 — lifecycle/persistencia de `TrainingPreferenceClarification`,
 * exclusivamente vía `TrainingPreferenceClarificationRecorder` (única
 * autoridad de escritura, mismo criterio que `TrainingPreferenceRecorder`).
 * Ningún test aquí resuelve identidad — eso es responsabilidad de
 * `PendingPreferenceClarificationResolver` (ver su propio test).
 */
function clarificationRecorder(): TrainingPreferenceClarificationRecorder
{
    return new TrainingPreferenceClarificationRecorder;
}

it('creates a new PENDING clarification with the given fields', function () {
    $contact = Contact::factory()->create();

    $pending = clarificationRecorder()->create(
        contact: $contact,
        dimension: PreferenceDimension::Exercise,
        candidateTerm: 'sentadillas',
        originalText: 'No me gustan las sentadillas.',
        presentedOptions: ['Sentadilla con banda', 'Sentadilla sumo'],
        totalMatches: 2,
    );

    expect($pending->status)->toBe(TrainingPreferenceClarificationStatus::Pending);
    expect($pending->dimension)->toBe(PreferenceDimension::Exercise);
    expect($pending->original_candidate_term)->toBe('sentadillas');
    expect($pending->original_text)->toBe('No me gustan las sentadillas.');
    expect($pending->presented_options)->toBe(['Sentadilla con banda', 'Sentadilla sumo']);
    expect($pending->total_matches)->toBe(2);
    expect($pending->expires_at)->not->toBeNull();
    expect($pending->resolved_at)->toBeNull();
    expect($pending->abandoned_at)->toBeNull();
    expect($pending->expired_at)->toBeNull();
});

it('resolve() marks the pending as RESOLVED with resolved_at, never deletes the row', function () {
    $contact = Contact::factory()->create();
    $pending = clarificationRecorder()->create($contact, PreferenceDimension::Exercise, 'sentadillas', 'texto', ['A'], 1);

    clarificationRecorder()->resolve($pending);

    $fresh = $pending->fresh();
    expect($fresh->status)->toBe(TrainingPreferenceClarificationStatus::Resolved);
    expect($fresh->resolved_at)->not->toBeNull();
    expect(TrainingPreferenceClarification::count())->toBe(1); // sigue existiendo, nunca se borra
});

it('resolve() is a no-op when the row is not PENDING (never throws)', function () {
    $contact = Contact::factory()->create();
    $pending = TrainingPreferenceClarification::factory()->resolved()->create(['contact_id' => $contact->id]);

    clarificationRecorder()->resolve($pending);

    expect($pending->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Resolved);
});

it('abandonActiveFor() marks the active pending as ABANDONED with abandoned_at, never deletes', function () {
    $contact = Contact::factory()->create();
    $pending = clarificationRecorder()->create($contact, PreferenceDimension::Exercise, 'sentadillas', 'texto', ['A'], 1);

    clarificationRecorder()->abandonActiveFor($contact);

    $fresh = $pending->fresh();
    expect($fresh->status)->toBe(TrainingPreferenceClarificationStatus::Abandoned);
    expect($fresh->abandoned_at)->not->toBeNull();
    expect(TrainingPreferenceClarification::count())->toBe(1);
});

it('abandonActiveFor() is a no-op when there is no active pending (never throws)', function () {
    $contact = Contact::factory()->create();

    clarificationRecorder()->abandonActiveFor($contact);

    expect(TrainingPreferenceClarification::count())->toBe(0);
});

it('create() abandons the previous pending atomically before creating the new one — at most one PENDING per contact', function () {
    $contact = Contact::factory()->create();
    $first = clarificationRecorder()->create($contact, PreferenceDimension::Exercise, 'sentadillas', 'texto 1', ['A', 'B'], 2);

    $second = clarificationRecorder()->create($contact, PreferenceDimension::Exercise, 'sentadilla', 'texto 1', ['C'], 1);

    expect($first->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Abandoned);
    expect($second->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Pending);
    expect(TrainingPreferenceClarification::where('contact_id', $contact->id)->count())->toBe(2); // historial conservado, nunca se borra
    expect(TrainingPreferenceClarification::where('contact_id', $contact->id)->where('status', TrainingPreferenceClarificationStatus::Pending)->count())->toBe(1);
});

it('activePendingFor() returns null when there is no pending', function () {
    $contact = Contact::factory()->create();

    expect(TrainingPreferenceClarification::activePendingFor($contact))->toBeNull();
});

it('activePendingFor() returns the active pending', function () {
    $contact = Contact::factory()->create();
    $pending = clarificationRecorder()->create($contact, PreferenceDimension::Exercise, 'sentadillas', 'texto', ['A'], 1);

    expect(TrainingPreferenceClarification::activePendingFor($contact)?->id)->toBe($pending->id);
});

it('lazily expires a PENDING whose expires_at already passed, treating it as inexistent', function () {
    $contact = Contact::factory()->create();
    $pending = TrainingPreferenceClarification::factory()->expired()->create(['contact_id' => $contact->id]);

    expect(TrainingPreferenceClarification::activePendingFor($contact))->toBeNull();
    expect($pending->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Expired);
    expect($pending->fresh()->expired_at)->not->toBeNull();
});

it('cross-contact isolation: activePendingFor() never returns another contact\'s pending', function () {
    $contactA = Contact::factory()->create();
    $contactB = Contact::factory()->create();
    clarificationRecorder()->create($contactA, PreferenceDimension::Exercise, 'sentadillas', 'texto', ['A'], 1);

    expect(TrainingPreferenceClarification::activePendingFor($contactB))->toBeNull();
});

it('cross-tenant isolation: two contacts of different tenants never see each other\'s pending', function () {
    $tenantA = \App\Models\Tenant::factory()->create();
    $tenantB = \App\Models\Tenant::factory()->create();
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);
    clarificationRecorder()->create($contactA, PreferenceDimension::Exercise, 'sentadillas', 'texto', ['A'], 1);

    expect(TrainingPreferenceClarification::activePendingFor($contactB))->toBeNull();
    expect(TrainingPreferenceClarification::activePendingFor($contactA))->not->toBeNull();
});

// ── Concurrencia (Parte 2 del encargo: "la restricción debe estar protegida
// también a nivel de base de datos") — mismo mecanismo ya validado por
// `reminder_suggestions` (columna generada + índice único): dos PENDING
// simultáneas para el mismo contacto son físicamente imposibles, sin
// importar si el código de aplicación las serializa o no. ──

it('the database rejects a second PENDING row for the same contact, even bypassing the Recorder', function () {
    $contact = Contact::factory()->create();
    TrainingPreferenceClarification::factory()->create(['contact_id' => $contact->id]);

    expect(fn () => TrainingPreferenceClarification::factory()->create(['contact_id' => $contact->id]))
        ->toThrow(QueryException::class);
});
