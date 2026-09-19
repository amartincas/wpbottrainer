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

/**
 * Hito 9.3 (sincronización completa) — `hasVideo` es la señal cruda que
 * YMove reporta en modo browse, sin costo de cuota. Nunca se inventa
 * cuando el proveedor no la informa.
 */
it('maps hasVideo true/false/absent into the normalized contract without inventing it', function () {
    $normalizer = new YMoveExerciseNormalizer;

    $withVideo = $normalizer->normalize(new ProviderExerciseData('id-8', [
        'id' => 'id-8', 'title' => 'Something', 'muscleGroup' => 'back', 'equipment' => 'cable', 'hasVideo' => true,
    ]));
    $withoutVideo = $normalizer->normalize(new ProviderExerciseData('id-9', [
        'id' => 'id-9', 'title' => 'Something else', 'muscleGroup' => 'back', 'equipment' => 'cable', 'hasVideo' => false,
    ]));
    $absent = $normalizer->normalize(new ProviderExerciseData('id-10', [
        'id' => 'id-10', 'title' => 'Yet another', 'muscleGroup' => 'back', 'equipment' => 'cable',
    ]));

    expect($withVideo->hasVideo)->toBeTrue();
    expect($withoutVideo->hasVideo)->toBeFalse();
    expect($absent->hasVideo)->toBeNull();
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

/**
 * Hito 9.3 (post-deploy) — hallazgo real: EQUIPMENT_MAP solo cubría 11 de
 * los 22 valores reales que YMove reporta (confirmado en vivo contra
 * GET /exercises/equipment, endpoint de metadata sin costo). Los otros 11
 * caían silenciosamente en `equipmentNeeded=[]` ("sin equipo"), aunque no
 * lo fueran. Verifica los 13 agregados en esta corrección.
 */
it('maps the full official YMove equipment vocabulary, not just the original 11', function () {
    $cases = [
        'mat' => ['mat'],
        'chair' => ['chair'],
        'box' => ['box'],
        'weighted vest' => ['weighted_vest'],
        'smith machine' => ['smith_machine'],
        'stability ball' => ['stability_ball'],
        'wall' => ['wall'],
        'cone' => ['cone'],
        'free weights' => ['free_weights'],
        'landmine' => ['landmine'],
        'foam roller' => ['foam_roller'],
        'step' => ['step'],
        'towel' => ['towel'],
    ];

    foreach ($cases as $raw => $expected) {
        $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-x', [
            'id' => 'id-x', 'title' => 'Something', 'muscleGroup' => 'back', 'equipment' => $raw,
        ]));

        expect($normalized->equipmentNeeded)->toBe($expected, "equipment '{$raw}' debería mapear a {$expected[0]}");
    }
});

it('degrades to no-equipment (never crashes) for a raw equipment string outside even the full official vocabulary', function () {
    $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-y', [
        'id' => 'id-y', 'title' => 'Something', 'muscleGroup' => 'back', 'equipment' => 'a brand new gadget ymove never told us about',
    ]));

    expect($normalized->equipmentNeeded)->toBe([]);
});

/**
 * Hito 15.2 — auditoría real (contact_id=28, exercise_id=710 servido a una
 * usuaria `home` sin barra de dominadas). YMove etiqueta `equipment:
 * "bodyweight"` tanto para ejercicios sin nada que necesitar como para
 * ejercicios que exigen un aparato/superficie fija. Ver
 * YMoveExerciseNormalizer::refineBodyweightEquipment().
 */
it('keeps true bodyweight exercises at no-equipment (case 1)', function () {
    // Caso real del catálogo: id=102 "Bodyweight Squat" — sin ninguna
    // mención de aparato en title/description/instructions/importantPoints.
    $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-102', [
        'id' => 'id-102',
        'title' => 'Bodyweight Squat',
        'muscleGroup' => 'quads',
        'equipment' => 'bodyweight',
        'description' => 'Stand with feet shoulder-width apart, toes slightly turned out, chest up and core braced.',
        'instructions' => ['Stand tall with feet shoulder-width apart.', 'Drive through your heels to extend the hips and knees back to standing.'],
        'importantPoints' => ['Keep your heels flat on the floor throughout the movement.'],
    ]));

    expect($normalized->equipmentNeeded)->toBe([]);
});

it('refines a bodyweight exercise that requires a pull-up bar (case 2, real exercise_id=710)', function () {
    $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-710', [
        'id' => 'id-710',
        'title' => 'Pull Up (Neutral Grip)',
        'muscleGroup' => 'back',
        'equipment' => 'bodyweight',
        'description' => 'Starting position: Hang from pull-up bar with palms facing each other, shoulder-width apart. Execution: Pull body up until chin clears bar, then lower with control.',
        'instructions' => ['Grip the bar with palms facing each other', 'Hang with arms fully extended and shoulders engaged'],
        'importantPoints' => ["Don't let shoulders roll forward at the bottom position"],
    ]));

    expect($normalized->equipmentNeeded)->toBe(['pull_up_bar']);
});

it('refines a bodyweight exercise that requires a bench (case 3, real exercise_id=81)', function () {
    $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-81', [
        'id' => 'id-81',
        'title' => 'Bench Dips',
        'muscleGroup' => 'triceps',
        'equipment' => 'bodyweight',
        'description' => 'Starting position: Sit on the edge of a bench with hands gripping the edge beside your hips.',
        'instructions' => ['Grip the edge of the bench with hands shoulder-width apart and slide your hips off the front of the bench.'],
        'importantPoints' => ['Keep your back close to the bench to reduce shoulder strain.'],
    ]));

    expect($normalized->equipmentNeeded)->toBe(['bench']);
});

it('does not let a verb collide with an apparatus noun when refining bodyweight (case 4, false positives)', function () {
    $normalizer = new YMoveExerciseNormalizer;

    // Caso real: id=121 "Burpee (No jump)" — "step" aparece como verbo
    // ("Step your right foot back"), no como el aparato "step platform".
    $burpee = $normalizer->normalize(new ProviderExerciseData('id-121', [
        'id' => 'id-121',
        'title' => 'Burpee (No jump)',
        'muscleGroup' => 'full_body',
        'equipment' => 'bodyweight',
        'instructions' => ['Step your right foot back into plank position, then step your left foot back to join it'],
    ]));
    expect($burpee->equipmentNeeded)->toBe([]);

    // "tracking" no debe activar "rack".
    $tracking = $normalizer->normalize(new ProviderExerciseData('id-tracking', [
        'id' => 'id-tracking', 'title' => 'Something', 'muscleGroup' => 'legs', 'equipment' => 'bodyweight',
        'importantPoints' => ['Ensure knees track in line with your toes, not caving inward.'],
    ]));
    expect($tracking->equipmentNeeded)->toBe([]);

    // "hamstrings" no debe activar "rings".
    $hamstrings = $normalizer->normalize(new ProviderExerciseData('id-hamstrings', [
        'id' => 'id-hamstrings', 'title' => 'Hip hinge', 'muscleGroup' => 'legs', 'equipment' => 'bodyweight',
        'instructions' => ['Lower until you feel tension in the hamstrings, keeping the back straight.'],
    ]));
    expect($hamstrings->equipmentNeeded)->toBe([]);
});

it('still resolves bodyweight to no-equipment when no raw payload is available for refinement (backward-compatible mapEquipment call)', function () {
    $normalizer = new YMoveExerciseNormalizer;

    expect($normalizer->mapEquipment('bodyweight'))->toBe([]);
    expect($normalizer->mapEquipment('bodyweight', null))->toBe([]);
});

/**
 * Nota sobre "24" vs. "22": la auditoría del catálogo real (Hito 15.2)
 * confirmó 22 valores crudos DISTINTOS en los 1068 ejercicios de YMove
 * (activos e inactivos). EQUIPMENT_MAP, en cambio, tiene 24 claves además
 * de 'bodyweight' (25 en total) — dos más que las 22 auditadas: 'dumbbells'
 * y 'bands' (plural), alias defensivos preexistentes desde Hito 9.3, nunca
 * observados como valor crudo real en la auditoría completa del catálogo
 * (que solo encontró las formas singulares 'dumbbell'/'band'). No son un
 * error de este hito ni se tocan aquí — este test verifica el mapa TAL
 * COMO EXISTE HOY (24 claves), no los 22 valores confirmados por auditoría.
 */
it('keeps the exact same mapping for the other 24 EQUIPMENT_MAP keys, unaffected by the bodyweight refinement (case 5, regression)', function () {
    $normalizer = new YMoveExerciseNormalizer;

    $cases = [
        'barbell' => ['barbell'], 'dumbbell' => ['dumbbells'], 'dumbbells' => ['dumbbells'],
        'kettlebell' => ['kettlebell'], 'cable' => ['cable_machine'], 'machine' => ['machine'],
        'band' => ['resistance_bands'], 'bands' => ['resistance_bands'], 'bench' => ['bench'],
        'pull-up bar' => ['pull_up_bar'], 'medicine ball' => ['medicine_ball'], 'mat' => ['mat'],
        'chair' => ['chair'], 'box' => ['box'], 'weighted vest' => ['weighted_vest'],
        'smith machine' => ['smith_machine'], 'stability ball' => ['stability_ball'], 'wall' => ['wall'],
        'cone' => ['cone'], 'free weights' => ['free_weights'], 'landmine' => ['landmine'],
        'foam roller' => ['foam_roller'], 'step' => ['step'], 'towel' => ['towel'],
    ];

    foreach ($cases as $raw => $expected) {
        // Título deliberadamente genérico (sin ninguna señal de aparato) —
        // estos 24 valores no pasan por refineBodyweightEquipment() en
        // absoluto (solo se dispara cuando el valor crudo es 'bodyweight'),
        // así que el título es irrelevante para el resultado esperado.
        expect($normalizer->mapEquipment($raw, ['title' => 'Some generic exercise title']))
            ->toBe($expected, "equipment '{$raw}' no debería verse afectado por el refinamiento de bodyweight");
    }
});
