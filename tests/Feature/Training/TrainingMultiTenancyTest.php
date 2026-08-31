<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;

/**
 * El dominio Training no denormaliza tenant_id en TrainingProfile/
 * WorkoutSession/TrainingAccess — igual que product_images no lo hace
 * respecto a products (ver docs/DECISIONS.md). Se escala vía Contact, que
 * ya es tenant-scoped. Estas pruebas confirman que esa cadena aísla los
 * datos entre tenants correctamente, y que Exercise es realmente global.
 */

it('never mixes workout sessions between contacts of different tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    TrainingProfile::factory()->create(['contact_id' => $contactA->id]);
    TrainingProfile::factory()->create(['contact_id' => $contactB->id]);

    WorkoutSession::factory()->create(['contact_id' => $contactA->id]);
    WorkoutSession::factory()->create(['contact_id' => $contactB->id]);

    $tenantASessions = WorkoutSession::whereHas(
        'contact',
        fn ($query) => $query->where('tenant_id', $tenantA->id)
    )->get();

    expect($tenantASessions)->toHaveCount(1);
    expect($tenantASessions->first()->contact_id)->toBe($contactA->id);
});

it('shares the Exercise catalog globally across tenants — no tenant_id column', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('exercises', 'tenant_id'))->toBeFalse();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $exercise = Exercise::factory()->create();

    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    // El mismo Exercise es referenciable desde WorkoutExercise de cualquier
    // tenant — no hay una copia por tenant del catálogo.
    $weA = \App\Models\WorkoutExercise::factory()->create([
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'workout_session_id' => WorkoutSession::factory()->create(['contact_id' => $contactA->id])->id,
    ]);
    $weB = \App\Models\WorkoutExercise::factory()->create([
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'workout_session_id' => WorkoutSession::factory()->create(['contact_id' => $contactB->id])->id,
    ]);

    expect($weA->exercise_id)->toBe($weB->exercise_id);
});

it('does not denormalize tenant_id on TrainingProfile/WorkoutSession/TrainingAccess, consistent with the ProductImage precedent', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('training_profiles', 'tenant_id'))->toBeFalse();
    expect(\Illuminate\Support\Facades\Schema::hasColumn('workout_sessions', 'tenant_id'))->toBeFalse();
    expect(\Illuminate\Support\Facades\Schema::hasColumn('training_accesses', 'tenant_id'))->toBeFalse();
});
