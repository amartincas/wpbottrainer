<?php

use App\Models\TrainingAccess;
use App\Training\Enums\TrainingAccessStatus;

it('is valid when active/trial and not expired', function () {
    $active = TrainingAccess::factory()->create(['status' => TrainingAccessStatus::Active, 'expires_at' => null]);
    $trial = TrainingAccess::factory()->create(['status' => TrainingAccessStatus::Trial, 'expires_at' => now()->addWeek()]);

    expect($active->isCurrentlyValid())->toBeTrue();
    expect($trial->isCurrentlyValid())->toBeTrue();
});

it('is not valid when expired or revoked', function () {
    $expired = TrainingAccess::factory()->expired()->create();
    $revoked = TrainingAccess::factory()->revoked()->create();
    $pastExpiry = TrainingAccess::factory()->create([
        'status' => TrainingAccessStatus::Active,
        'expires_at' => now()->subDay(),
    ]);

    expect($expired->isCurrentlyValid())->toBeFalse();
    expect($revoked->isCurrentlyValid())->toBeFalse();
    expect($pastExpiry->isCurrentlyValid())->toBeFalse();
});

it('does not carry any billing/subscription concept', function () {
    $columns = \Illuminate\Support\Facades\Schema::getColumnListing('training_accesses');

    // Hito 8: 'payment_id' se agregó como trazabilidad de QUÉ Payment
    // originó/extendió el acceso — sigue sin ser un concepto de
    // facturación/suscripción en sí (no hay monto, moneda, ciclo de
    // facturación, ni plan aquí; eso vive en Payment). Hito 15:
    // 'trial_granted_at' es una marca histórica INMUTABLE (manual O
    // automático), tampoco un concepto de facturación — nunca un monto, ni
    // un ciclo, ni un plan. Ver docs/DECISIONS.md.
    expect($columns)->toEqualCanonicalizing([
        'id', 'contact_id', 'payment_id', 'status', 'granted_at', 'expires_at', 'granted_by', 'notes', 'trial_granted_at', 'created_at', 'updated_at',
    ]);
});
