<?php

use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseNormalizer;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\TrackingType;

/**
 * Hito 9.1: casos derivados de la auditoría REAL de la prueba técnica de
 * YMove (ver docs/DECISIONS.md) — incluido el caso ya observado en
 * producción de `difficulty: null` para "Barbell Hip Thrust".
 */
it('normalizes a real YMove exercise shape into our closed vocabulary', function () {
    $raw = new ProviderExerciseData('5990deac-a91d-4531-8c2f-a708fc95fd1f', [
        'id' => '5990deac-a91d-4531-8c2f-a708fc95fd1f',
        'title' => 'Barbell Hip Thrust',
        'description' => 'Lie face up with shoulders supported on a bench...',
        'instructions' => ['Upper back on bench, bar over hips', 'Drive hips up by squeezing glutes'],
        'muscleGroup' => 'glutes',
        'secondaryMuscles' => null,
        'equipment' => 'barbell',
        'category' => 'Legs',
        'difficulty' => null, // caso real observado — nunca se inventa
        'videoDurationSecs' => null,
        'exerciseType' => ['strength'],
        'videoUrl' => 'https://cdn.example/video.mp4?expires=123',
    ]);

    $normalized = (new YMoveExerciseNormalizer)->normalize($raw);

    expect($normalized->provider)->toBe('ymove');
    expect($normalized->providerExerciseId)->toBe('5990deac-a91d-4531-8c2f-a708fc95fd1f');
    expect($normalized->name)->toBe('Barbell Hip Thrust');
    expect($normalized->primaryMuscle)->toBe(MuscleFocus::Glutes);
    expect($normalized->secondaryMuscles)->toBe([]);
    expect($normalized->muscleGroupCoarse)->toBe('legs');
    expect($normalized->equipmentNeeded)->toBe(['barbell']);
    expect($normalized->difficultyLevel)->toBeNull(); // nunca inventado
    expect($normalized->movementPattern)->toBeNull(); // sin heurística confiable, nunca inventado
    expect($normalized->trackingType)->toBe(TrackingType::RepsAndLoad);
    expect($normalized->instructions)->toHaveCount(2);
    // Hito 9.2: YMove no provee esto en absoluto — nunca se inventa.
    expect($normalized->commonMistakes)->toBe([]);
    expect($normalized->breathingCue)->toBeNull();
});

it('maps importantPoints[] into our normalized contract', function () {
    $raw = new ProviderExerciseData('id-6', [
        'id' => 'id-6', 'title' => 'Something', 'muscleGroup' => 'back', 'equipment' => 'cable',
        'importantPoints' => ['Keep your core braced', 'Do not round your back'],
    ]);

    $normalized = (new YMoveExerciseNormalizer)->normalize($raw);

    expect($normalized->importantPoints)->toBe(['Keep your core braced', 'Do not round your back']);
});

it('never invents common_mistakes or breathing_cue, even for an exercise with rich instructions/importantPoints', function () {
    $raw = new ProviderExerciseData('id-7', [
        'id' => 'id-7', 'title' => 'Something', 'muscleGroup' => 'chest', 'equipment' => 'barbell',
        'instructions' => ['Paso 1', 'Paso 2'],
        'importantPoints' => ['Punto 1', 'Punto 2'],
    ]);

    $normalized = (new YMoveExerciseNormalizer)->normalize($raw);

    expect($normalized->commonMistakes)->toBe([]);
    expect($normalized->breathingCue)->toBeNull();
});

it('maps a difficulty value when YMove does provide one', function () {
    $raw = new ProviderExerciseData('id-1', [
        'id' => 'id-1',
        'title' => 'Something',
        'muscleGroup' => 'chest',
        'equipment' => 'bodyweight',
        'difficulty' => 'advanced',
    ]);

    $normalized = (new YMoveExerciseNormalizer)->normalize($raw);

    expect($normalized->difficultyLevel)->toBe(ExperienceLevel::Advanced);
    expect($normalized->equipmentNeeded)->toBe([]); // bodyweight = sin equipo
});

it('infers time-based tracking only from clear stretch/hold/pose keywords, defaulting to reps-and-load otherwise', function () {
    $stretch = new ProviderExerciseData('id-2', [
        'id' => 'id-2', 'title' => '90/90 Stretch', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight',
    ]);
    $strength = new ProviderExerciseData('id-3', [
        'id' => 'id-3', 'title' => 'Barbell Hip Thrust', 'muscleGroup' => 'glutes', 'equipment' => 'barbell',
    ]);

    $normalizer = new YMoveExerciseNormalizer;

    expect($normalizer->normalize($stretch)->trackingType)->toBe(TrackingType::TimeBased);
    expect($normalizer->normalize($strength)->trackingType)->toBe(TrackingType::RepsAndLoad);
});

it('discards a muscleGroup value it does not recognize instead of guessing', function () {
    $raw = new ProviderExerciseData('id-4', [
        'id' => 'id-4', 'title' => 'Mystery move', 'muscleGroup' => 'some_new_thing_ymove_added', 'equipment' => 'bodyweight',
    ]);

    $normalized = (new YMoveExerciseNormalizer)->normalize($raw);

    expect($normalized->primaryMuscle)->toBeNull();
});

it('preserves the full raw payload as opaque metadata, never as the normalized contract', function () {
    $raw = new ProviderExerciseData('id-5', [
        'id' => 'id-5', 'title' => 'Something', 'muscleGroup' => 'back', 'equipment' => 'cable',
        'importantPoints' => ['Keep your core braced'],
    ]);

    $normalized = (new YMoveExerciseNormalizer)->normalize($raw);

    expect($normalized->rawMetadata)->toBe($raw->raw);
    expect($normalized->equipmentNeeded)->toBe(['cable_machine']);
});
