<?php

use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseNormalizer;
use App\Training\Enums\Equipment;
use App\Training\Enums\ExerciseType;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\TrackingType;
use Illuminate\Support\Facades\Log;

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

/**
 * Hito Provider-Agnostic Normalization — CAMBIO DE COMPORTAMIENTO deliberado.
 * Antes de este hito, este mismo test esperaba `[]` ("sin equipo") para un
 * valor de equipo desconocido — ese era exactamente el bug que Audit #3/#4
 * encontraron: un ejercicio que SÍ exige equipo (pero de un tipo que el
 * normalizer no reconoce) quedaba indistinguible de uno genuinamente sin
 * equipo, apareciendo como "elegible" para un usuario sin nada. Ahora un
 * valor desconocido produce `Equipment::Unsupported`, nunca `[]`.
 */
it('marks a raw equipment string outside the vocabulary as Unsupported, never as no-equipment', function () {
    $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-y', [
        'id' => 'id-y', 'title' => 'Something', 'muscleGroup' => 'back', 'equipment' => 'a brand new gadget ymove never told us about',
    ]));

    expect($normalized->equipmentNeeded)->toBe(['unsupported']);
    expect($normalized->equipmentNeeded)->not->toBe([]);
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
 * (activos e inactivos). Estas 24 claves (además de 'bodyweight') son dos
 * más que las 22 auditadas: 'dumbbells' y 'bands' (plural), alias
 * defensivos preexistentes desde Hito 9.3, nunca observados como valor
 * crudo real en la auditoría completa del catálogo (que solo encontró las
 * formas singulares 'dumbbell'/'band'). No son un error de este hito ni se
 * tocan aquí.
 *
 * Hito Provider-Agnostic Normalization — corrección de una afirmación
 * desactualizada que este mismo docblock tenía: decía que EQUIPMENT_MAP
 * "tiene 24 claves además de bodyweight (25 en total)" como si fuera el
 * total completo del mapa — eso dejó de ser cierto en cuanto este hito
 * agregó 10 claves más (rings/dip bar/bosu/plate/suspension trainer/battle
 * rope/mini band/trap bar/ez bar/push-up handles), llevando el mapa a 34
 * claves además de bodyweight (35 en total). Este test sigue verificando
 * ESPECÍFICAMENTE estas 24 claves originales (un subconjunto deliberado,
 * no "todo el mapa") — las 10 nuevas tienen su propio test dedicado
 * ('maps the 10 newly-supported YMove equipment values...', arriba).
 */
it('keeps the exact same mapping for these 24 original EQUIPMENT_MAP keys, unaffected by the bodyweight refinement (case 5, regression)', function () {
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

// ── Hito Provider-Agnostic Normalization ────────────────────────────────

/**
 * Audit #4 — clasificación caso por caso de los 11 valores reales de
 * equipment antes sin mapeo, verificada contra ejemplos reales del
 * catálogo vivo. 6 son objetos físicamente distintos (casos nuevos de
 * Equipment), 3 son variantes con pérdida de granularidad aceptada
 * (mapeados a un valor existente), 1 se trata como accesorio de confort
 * (sin equipo), y 1 (ab wheel) queda deliberadamente fuera del mapa.
 */
it('maps the 10 newly-supported YMove equipment values to their domain decision', function () {
    $normalizer = new YMoveExerciseNormalizer;

    $cases = [
        // Objeto físico distinto → caso nuevo de Equipment.
        'rings' => ['rings'],
        'dip bar' => ['dip_bar'],
        'bosu' => ['bosu'],
        'plate' => ['plate'],
        'suspension trainer' => ['suspension_trainer'],
        'battle rope' => ['battle_rope'],
        // Variante de equipo ya representado, pérdida aceptada.
        'mini band' => ['resistance_bands'],
        'trap bar' => ['barbell'],
        'ez bar' => ['barbell'],
        // Accesorio de confort — no cambia la demanda real del movimiento.
        'push-up handles' => [],
    ];

    foreach ($cases as $raw => $expected) {
        expect($normalizer->mapEquipment($raw, ['title' => 'Some exercise']))
            ->toBe($expected, "equipment '{$raw}' debería mapear a ".json_encode($expected));
    }
});

it('leaves "ab wheel" deliberately unmapped, falling through to Unsupported like any other unknown value', function () {
    $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-abwheel', [
        'id' => 'id-abwheel', 'title' => 'Kneeling Ab Wheel Rollout', 'muscleGroup' => 'core', 'equipment' => 'ab wheel',
    ]));

    expect($normalized->equipmentNeeded)->toBe([Equipment::Unsupported->value]);
});

it('logs EXERCISE_EQUIPMENT_UNSUPPORTED with identifying context when equipment falls back to Unsupported', function () {
    Log::spy();

    (new YMoveExerciseNormalizer)->mapEquipment('a totally new gadget', [
        'id' => 'provider-id-123', 'title' => 'Mystery Move',
    ]);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $message === 'EXERCISE_EQUIPMENT_UNSUPPORTED'
            && $context['provider_exercise_id'] === 'provider-id-123'
            && $context['exercise_name'] === 'Mystery Move'
            && $context['raw_equipment'] === 'a totally new gadget'
    );
});

it('never logs EXERCISE_EQUIPMENT_UNSUPPORTED for a genuinely known value, including bodyweight', function () {
    Log::spy();

    (new YMoveExerciseNormalizer)->mapEquipment('bodyweight', ['title' => 'Push Ups']);
    (new YMoveExerciseNormalizer)->mapEquipment('dumbbells', ['title' => 'Dumbbell Curl']);

    Log::shouldNotHaveReceived('warning');
});

/**
 * Audit #4 — 14 aliases seguros verificados contra ejemplos reales del
 * catálogo (ej. "quadriceps" → Dumbbell Goblet Squat / Wall Sit, claramente
 * cuádriceps). Cada uno mapea a un MuscleFocus YA EXISTENTE — ningún caso
 * nuevo se agrega al enum para esto.
 */
it('maps the 14 newly-supported YMove muscleGroup aliases to an existing MuscleFocus', function () {
    $cases = [
        'quadriceps' => MuscleFocus::Quads,
        'full body' => MuscleFocus::FullBody,
        'lats' => MuscleFocus::Back,
        'erector_spinae' => MuscleFocus::Back,
        'lower_back' => MuscleFocus::Back,
        'glute_med' => MuscleFocus::Glutes,
        'lower_abs' => MuscleFocus::Abs,
        'obliques' => MuscleFocus::Abs,
        'rectus_abdominis' => MuscleFocus::Abs,
        'upper_chest' => MuscleFocus::Chest,
        'lower_chest' => MuscleFocus::Chest,
        'front_deltoids' => MuscleFocus::Shoulders,
        'lateral_deltoids' => MuscleFocus::Shoulders,
        'rear_deltoids' => MuscleFocus::Shoulders,
    ];

    foreach ($cases as $raw => $expected) {
        $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-mg', [
            'id' => 'id-mg', 'title' => 'Something', 'muscleGroup' => $raw, 'equipment' => 'bodyweight',
        ]));

        expect($normalized->primaryMuscle)->toBe($expected, "muscleGroup '{$raw}' debería mapear a {$expected->value}");
    }
});

it('never forces an ambiguous muscleGroup value into an existing MuscleFocus — stays null', function () {
    $ambiguous = ['legs', 'forearms', 'forearm_flexors', 'brachioradialis', 'hip_flexors', 'neck', 'abductors', 'adductors', 'tibialis_anterior', 'lower_traps', 'middle_traps'];

    foreach ($ambiguous as $raw) {
        $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-amb', [
            'id' => 'id-amb', 'title' => 'Something', 'muscleGroup' => $raw, 'equipment' => 'bodyweight',
        ]));

        expect($normalized->primaryMuscle)->toBeNull("muscleGroup '{$raw}' no debería forzarse a ningún MuscleFocus");
    }
});

/**
 * Audit #4 — YMove pone en `muscleGroup` valores que en realidad son un
 * dato de OTRO campo (equipment) mal ubicado, o una categoría de
 * sistema/tipo — nunca un músculo real. Nunca deben convertirse en un
 * MuscleFocus, sin importar cuán "conocido" suene el string.
 */
it('never converts invalid provider data (bodyweight/ketllebell/smith-machine/cardio as muscleGroup) into a muscle', function () {
    $invalidValues = ['bodyweight', 'ketllebell', 'kettlebell-exercises', 'smith-machine', 'cardio', 'cardiovascular_system'];

    foreach ($invalidValues as $raw) {
        $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-inv', [
            'id' => 'id-inv', 'title' => 'Something', 'muscleGroup' => $raw, 'equipment' => 'dumbbell',
        ]));

        expect($normalized->primaryMuscle)->toBeNull("'{$raw}' es un dato inválido del proveedor, nunca debería convertirse en músculo");
    }
});

it('logs invalid provider muscleGroup data distinctly (info/invalid) from a merely unsupported value (info/unsupported)', function () {
    Log::spy();

    (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-x1', [
        'id' => 'id-x1', 'title' => 'Smith Machine Squats', 'muscleGroup' => 'smith-machine', 'equipment' => 'machine',
    ]));
    (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-x2', [
        'id' => 'id-x2', 'title' => 'Some Leg Move', 'muscleGroup' => 'legs', 'equipment' => 'bodyweight',
    ]));

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context) => $message === 'EXERCISE_MUSCLE_GROUP_INVALID' && $context['raw_muscle_group'] === 'smith-machine'
    )->once();

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context) => $message === 'EXERCISE_MUSCLE_GROUP_UNSUPPORTED' && $context['raw_muscle_group'] === 'legs'
    )->once();
});

/**
 * exercise_type ahora pasa por un vocabulario propio de dominio
 * (App\Training\Enums\ExerciseType), nunca un pass-through crudo del
 * proveedor — un valor no reconocido se descarta, nunca se inventa un
 * caso de enum nuevo para conservarlo.
 */
it('normalizes known exerciseType values into the domain ExerciseType vocabulary', function () {
    $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-et', [
        'id' => 'id-et', 'title' => 'Something', 'muscleGroup' => 'back', 'equipment' => 'bodyweight',
        'exerciseType' => ['strength', 'balance', 'functional', 'core', 'mobility', 'cardio', 'calisthenics', 'stretching', 'yoga', 'plyometric', 'isometric', 'warmup', 'rehabilitation', 'hiit', 'cooldown'],
    ]));

    expect($normalized->exerciseType)->toBe([
        ExerciseType::Strength->value, ExerciseType::Balance->value, ExerciseType::Functional->value, ExerciseType::Core->value,
        ExerciseType::Mobility->value, ExerciseType::Cardio->value, ExerciseType::Calisthenics->value, ExerciseType::Stretching->value,
        ExerciseType::Yoga->value, ExerciseType::Plyometric->value, ExerciseType::Isometric->value, ExerciseType::Warmup->value,
        ExerciseType::Rehabilitation->value, ExerciseType::Hiit->value, ExerciseType::Cooldown->value,
    ]);
});

it('discards an unrecognized exerciseType value instead of fabricating a new category', function () {
    $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-et2', [
        'id' => 'id-et2', 'title' => 'Something', 'muscleGroup' => 'back', 'equipment' => 'bodyweight',
        'exerciseType' => ['strength', 'a brand new type ymove invented'],
    ]));

    expect($normalized->exerciseType)->toBe([ExerciseType::Strength->value]);
    expect($normalized->exerciseType)->not->toContain('a brand new type ymove invented');
});

it('logs discarded exerciseType values once per exercise (aggregated), not once per value', function () {
    Log::spy();

    (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-et3', [
        'id' => 'id-et3', 'title' => 'Something', 'muscleGroup' => 'back', 'equipment' => 'bodyweight',
        'exerciseType' => ['strength', 'made up type one', 'made up type two'],
    ]));

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context) => $message === 'EXERCISE_TYPE_UNSUPPORTED'
            && $context['raw_exercise_type_discarded'] === ['made up type one', 'made up type two']
    )->once();
});

/**
 * secondary_muscles usa el MISMO MUSCLE_GROUP_MAP que primary_muscle — un
 * valor soportado (incluidos los 14 aliases nuevos) se conserva, uno no
 * soportado se filtra silenciosamente (comportamiento ya existente,
 * verificado aquí explícitamente) sin inventarse como otra categoría.
 */
it('preserves supported secondary muscles and filters out unsupported ones without inventing a category', function () {
    $normalized = (new YMoveExerciseNormalizer)->normalize(new ProviderExerciseData('id-sm', [
        'id' => 'id-sm', 'title' => 'Something', 'muscleGroup' => 'chest', 'equipment' => 'barbell',
        'secondaryMuscles' => ['triceps', 'lats', 'serratus_anterior', 'front_deltoids'],
    ]));

    // 'triceps' (directo) y 'lats'/'front_deltoids' (aliases nuevos)
    // sobreviven; 'serratus_anterior' (sin mapeo) se filtra, nunca se
    // convierte en otro músculo.
    expect($normalized->secondaryMuscles)->toBe([MuscleFocus::Triceps, MuscleFocus::Back, MuscleFocus::Shoulders]);
});
