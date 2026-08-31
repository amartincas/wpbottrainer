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

    expect($columns)->toEqualCanonicalizing([
        'id', 'contact_id', 'status', 'granted_at', 'expires_at', 'granted_by', 'notes', 'created_at', 'updated_at',
    ]);
});
