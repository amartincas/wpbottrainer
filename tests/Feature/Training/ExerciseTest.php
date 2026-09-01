<?php

use App\Models\Exercise;
use App\Training\Enums\TrackingType;

it('casts array and boolean fields correctly', function () {
    $exercise = Exercise::factory()->create([
        'equipment_needed' => ['barbell'],
        'contraindications' => ['knee'],
        'is_active' => true,
    ]);

    $fresh = $exercise->fresh();

    expect($fresh->equipment_needed)->toBe(['barbell']);
    expect($fresh->contraindications)->toBe(['knee']);
    expect($fresh->is_active)->toBeTrue();
    expect($fresh->tracking_type)->toBe(TrackingType::RepsAndLoad);
});

it('can be retired from the active catalog without being deleted', function () {
    $exercise = Exercise::factory()->inactive()->create();

    expect($exercise->is_active)->toBeFalse();
    expect(Exercise::find($exercise->id))->not->toBeNull();
});

it('produces a snapshot with exactly the fields shown to the user', function () {
    $exercise = Exercise::factory()->create([
        'name' => 'Sentadilla',
        'instructions' => 'Baja controlando la rodilla.',
        'video_url' => 'https://videos.example.test/squat.mp4',
        'muscle_group' => 'legs',
    ]);

    $snapshot = $exercise->toSnapshot();

    expect($snapshot)->toBe([
        'name' => 'Sentadilla',
        'instructions' => 'Baja controlando la rodilla.',
        'video_url' => 'https://videos.example.test/squat.mp4',
        'muscle_group' => 'legs',
        'primary_muscle' => null,
        'secondary_muscles' => null,
    ]);
});
