<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\DurationEstimator;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\RequestedFocusGroup;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrainingHistoryContextProvider;
use App\Training\Support\TrainingPreferenceResolver;

// Hito B1 (Requested Focus) — mismo criterio de PrescriptionContextSnapshotTest.php
// (nombres de helper propios, prefijo "rfSnap", para evitar colisión).

function rfSnapContact(array $profileOverrides = []): Contact
{
    $contact = Contact::factory()->create();

    TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'split_type' => SplitType::FullBody,
        'goal' => TrainingGoal::GeneralFitness,
        'experience_level' => ExperienceLevel::Beginner,
    ], $profileOverrides));

    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

function rfSnapEngine(): TrainingEngine
{
    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);

    return new TrainingEngine(
        new TrainingAccessGate,
        $safetyResolver,
        new TrainingHistoryContextProvider($safetyResolver, new TrainingPreferenceResolver),
        new ProgressionEvaluator,
        new DurationEstimator,
        new TrainingPreferenceResolver,
    );
}

// ── 39/40: requested_focus y requested_focus_coverage persistidos ──

it('39-40: requested_focus and requested_focus_coverage are persisted with the expected shape', function () {
    // 3 candidatos: cubre exactamente el N=3 por defecto (30min/general_fitness)
    // para un único grupo (theoretical=3) -> status 'fulfilled', sin
    // depender de un ajuste fino de duración solo para este test de forma.
    for ($i = 0; $i < 3; $i++) {
        Exercise::factory()->create([
            'primary_muscle' => MuscleFocus::Chest,
            'difficulty_level' => 'beginner',
            'equipment_needed' => [],
        ]);
    }

    $contact = rfSnapContact();
    $groups = [new RequestedFocusGroup('chest', ['chest'])];

    $session = rfSnapEngine()->decideNextSession($contact->fresh(), $groups);
    $snapshot = $session->prescription_context_snapshot;

    expect($snapshot['requested_focus'])->toBe([
        ['key' => 'chest', 'muscles' => ['chest']],
    ]);

    expect($snapshot['requested_focus_coverage'])->toHaveCount(1);
    expect($snapshot['requested_focus_coverage'][0])->toHaveKeys([
        'key', 'slots_reserved', 'slots_filled', 'coverage', 'status', 'reason',
    ]);
    expect($snapshot['requested_focus_coverage'][0]['key'])->toBe('chest');
    expect($snapshot['requested_focus_coverage'][0]['status'])->toBe('fulfilled');
});

// ── 41: el snapshot sigue siendo plano — nunca un objeto "profile" anidado ──

it('41: the snapshot remains flat — requested_focus is additive, never a nested "profile" object', function () {
    Exercise::factory()->create([
        'primary_muscle' => MuscleFocus::Chest,
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);

    $contact = rfSnapContact([
        'goal' => TrainingGoal::BuildMuscle,
        'primary_focus' => ['back'],
    ]);
    $groups = [new RequestedFocusGroup('chest', ['chest'])];

    $session = rfSnapEngine()->decideNextSession($contact->fresh(), $groups);
    $snapshot = $session->prescription_context_snapshot;

    // Ningún key anidado — todas las claves preexistentes siguen accesibles
    // directamente en el primer nivel, exactamente igual que antes de B1
    // (mismo contrato que PrescriptionContextSnapshotTest.php).
    expect($snapshot)->not->toHaveKey('profile');
    expect($snapshot['goal'])->toBe('build_muscle');
    expect($snapshot['primary_focus'])->toBe(['back']);
    expect($snapshot['split_type'])->toBe('full_body');
    expect(array_keys($snapshot))->toContain('requested_focus', 'requested_focus_coverage', 'decided_focus', 'schema_version');
});

// ── 42: decided_focus sigue representando autonomousFocus, no requestedFocus ──

it('42: decided_focus keeps representing autonomousFocus, unaffected by requestedFocus', function () {
    Exercise::factory()->create([
        'primary_muscle' => MuscleFocus::Chest,
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);

    $contactWithoutRequest = rfSnapContact(['split_type' => SplitType::FullBody]);
    $contactWithRequest = rfSnapContact(['split_type' => SplitType::FullBody]);

    $sessionWithout = rfSnapEngine()->decideNextSession($contactWithoutRequest->fresh());
    $sessionWith = rfSnapEngine()->decideNextSession($contactWithRequest->fresh(), [new RequestedFocusGroup('chest', ['chest'])]);

    // Perfiles equivalentes (mismo split_type, sin historial) -> mismo
    // decided_focus autónomo, exista o no un requested_focus pedido — nunca
    // "chest" (el grupo solicitado), siempre el string de rotación.
    expect($sessionWith->prescription_context_snapshot['decided_focus'])
        ->toBe($sessionWithout->prescription_context_snapshot['decided_focus']);
    expect($sessionWith->prescription_context_snapshot['decided_focus'])->not->toBe('chest');
});

// ── 43: requestedFocus=null produce arrays vacíos, nunca null ──

it('43: requestedFocus=null produces empty arrays in the snapshot, never null', function () {
    Exercise::factory()->create([
        'muscle_group' => 'chest',
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);

    $contact = rfSnapContact();
    $session = rfSnapEngine()->decideNextSession($contact->fresh());
    $snapshot = $session->prescription_context_snapshot;

    expect($snapshot['requested_focus'])->toBe([]);
    expect($snapshot['requested_focus_coverage'])->toBe([]);
});

// ── 44: schema_version — decisión explícita, no asumida ──

it('44: schema_version stays 1 — additive, backward-compatible fields never bump it, with or without requestedFocus', function () {
    Exercise::factory()->create([
        'primary_muscle' => MuscleFocus::Chest,
        'muscle_group' => 'chest',
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);

    $contactA = rfSnapContact();
    $contactB = rfSnapContact();

    $sessionA = rfSnapEngine()->decideNextSession($contactA->fresh());
    $sessionB = rfSnapEngine()->decideNextSession($contactB->fresh(), [new RequestedFocusGroup('chest', ['chest'])]);

    // Ver docs/DECISIONS.md D046 punto 4: schema_version marca la FORMA del
    // snapshot para reinterpretar snapshots históricos. requested_focus/
    // requested_focus_coverage son PURAMENTE ADITIVOS: una clave ausente
    // (snapshot pre-B1) y una clave presente con `[]` significan EXACTAMENTE
    // lo mismo ("no se solicitó foco puntual") — no hay ninguna
    // reinterpretación de un campo EXISTENTE que dependa de saber si B1 ya
    // corrió, así que no hace falta versión nueva para distinguir ambos
    // casos. Se mantiene 1 deliberadamente (decisión explícita, no omisión).
    expect($sessionA->prescription_context_snapshot['schema_version'])->toBe(1);
    expect($sessionB->prescription_context_snapshot['schema_version'])->toBe(1);
});
