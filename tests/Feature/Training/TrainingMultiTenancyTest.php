<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\TrainingPreference;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\PreferenceStatus;
use App\Training\Support\TrainingPreferenceResolver;
use Illuminate\Support\Facades\Schema;

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
    expect(Schema::hasColumn('exercises', 'tenant_id'))->toBeFalse();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $exercise = Exercise::factory()->create();

    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    // El mismo Exercise es referenciable desde WorkoutExercise de cualquier
    // tenant — no hay una copia por tenant del catálogo.
    $weA = WorkoutExercise::factory()->create([
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'workout_session_id' => WorkoutSession::factory()->create(['contact_id' => $contactA->id])->id,
    ]);
    $weB = WorkoutExercise::factory()->create([
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'workout_session_id' => WorkoutSession::factory()->create(['contact_id' => $contactB->id])->id,
    ]);

    expect($weA->exercise_id)->toBe($weB->exercise_id);
});

it('does not denormalize tenant_id on TrainingProfile/WorkoutSession/TrainingAccess, consistent with the ProductImage precedent', function () {
    expect(Schema::hasColumn('training_profiles', 'tenant_id'))->toBeFalse();
    expect(Schema::hasColumn('workout_sessions', 'tenant_id'))->toBeFalse();
    expect(Schema::hasColumn('training_accesses', 'tenant_id'))->toBeFalse();
});

/**
 * Hito B3 (Preferencias persistentes) — mismo criterio exacto que el resto
 * del dominio Training: `training_preferences` tampoco denormaliza
 * `tenant_id`, y una preferencia de un Contact de un tenant NUNCA afecta la
 * selección de otro Contact de un tenant distinto.
 */
it('does not denormalize tenant_id on training_preferences', function () {
    expect(Schema::hasColumn('training_preferences', 'tenant_id'))->toBeFalse();
});

it('never lets a preference from Contact/Tenant A affect Contact/Tenant B, even with the same exercise_id', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    $exercise = Exercise::factory()->create();

    TrainingPreference::create([
        'contact_id' => $contactA->id,
        'dimension' => PreferenceDimension::Exercise,
        'exercise_id' => $exercise->id,
        'preference_key' => "exercise:{$exercise->id}",
        'status' => PreferenceStatus::Active,
        'original_text' => 'No me gustan los burpees.',
        'created_at' => now(),
    ]);

    $excludedForB = (new TrainingPreferenceResolver)->excludedIdentifiersFor($contactB);

    expect($excludedForB['exercise_ids'])->not->toContain($exercise->id);
});
