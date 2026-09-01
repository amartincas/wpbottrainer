<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrainingProfile;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
use App\Training\Memory\TrainingProfileContextProvider;

/**
 * First real ContextProvider (Hito 5). Must recover ONLY the TrainingProfile
 * of the user sending the message — nothing else (no WhatsApp history).
 */

it('returns unknown confidence and null data when the contact has no profile yet', function () {
    $tenant = Tenant::factory()->create();
    $context = new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage('573001112233', 'hola', 'wamid.1', 'text', null),
    );

    $fragment = (new TrainingProfileContextProvider)->provide($context);

    expect($fragment->label)->toBe('training_profile');
    expect($fragment->data)->toBeNull();
    expect($fragment->confidence)->toBe('unknown');
});

it('returns confirmed profile data for a contact that already has one', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Carlos']);
    TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'goal' => TrainingGoal::BuildMuscle,
        'experience_level' => ExperienceLevel::Beginner,
        'training_location' => TrainingLocation::Gym,
        'restrictions' => ['knee'],
        'available_equipment' => ['dumbbells'],
        'equipment_fully_equipped' => false,
        'sessions_per_week' => 4,
        'age' => 30,
        'weight_kg' => 80.5,
        'height_cm' => 175,
    ]);

    $context = new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage('573001112233', 'dame mi rutina', 'wamid.1', 'text', null),
    );

    $fragment = (new TrainingProfileContextProvider)->provide($context);

    expect($fragment->confidence)->toBe('confirmed');
    expect($fragment->source)->toBe('db');
    expect($fragment->data)->toBe([
        'name' => 'Carlos',
        'goal' => 'build_muscle',
        'experience_level' => 'beginner',
        'primary_focus' => [],
        'secondary_focus' => null,
        'training_location' => 'gym',
        'restrictions' => ['knee'],
        'available_equipment' => ['dumbbells'],
        'equipment_fully_equipped' => false,
        'sessions_per_week' => 4,
        'age' => 30,
        'sex' => null,
        'weight_kg' => 80.5,
        'height_cm' => 175,
    ]);
});

it('is resolvable through ContextBuilder under the training_profile key', function () {
    $tenant = Tenant::factory()->create();
    $builder = app(\App\Core\Memory\ContextBuilder::class);

    $context = new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage('573001112233', 'hola', 'wamid.1', 'text', null),
    );

    $fragments = $builder->build($context, ['training_profile']);

    expect($fragments)->toHaveCount(1);
    expect($fragments[0]->label)->toBe('training_profile');
});
