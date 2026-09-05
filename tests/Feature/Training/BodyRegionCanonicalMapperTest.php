<?php

use App\Training\Enums\BodyRegion;
use App\Training\Support\BodyRegionCanonicalMapper;

/**
 * Hito de seguridad de restricciones — catálogo de correspondencia exacta
 * y explícita, sembrado únicamente con los 8 valores reales verificados
 * contra producción en Exercise.contraindications. Nunca heurística, nunca
 * substring, nunca IA.
 */
it('normalizes each of the 8 real values currently used in production to its correct BodyRegion', function () {
    $mapper = new BodyRegionCanonicalMapper;

    $cases = [
        'lesión de hombro' => BodyRegion::Shoulder,
        'manguito rotador' => BodyRegion::Shoulder,
        'dolor lumbar agudo' => BodyRegion::LowerBack,
        'hernia discal' => BodyRegion::LowerBack,
        'lesión de rodilla' => BodyRegion::Knee,
        'lesión de muñeca' => BodyRegion::Wrist,
        'lesión de codo' => BodyRegion::Elbow,
        'lesión de tobillo' => BodyRegion::Ankle,
    ];

    foreach ($cases as $text => $expectedRegion) {
        expect($mapper->mapMany([$text]))->toBe([$expectedRegion]);
    }
});

it('never loses "manguito rotador" or "hernia discal" — neither contains a body region name as a substring', function () {
    // Estos dos son el hallazgo real que motivó un catálogo de
    // correspondencia exacta en vez de una heurística de palabra clave:
    // ninguno de los dos contiene "hombro" ni "espalda"/"lumbar" como
    // substring — un mapeador heurístico los perdería.
    $mapper = new BodyRegionCanonicalMapper;

    expect($mapper->mapMany(['manguito rotador']))->toBe([BodyRegion::Shoulder]);
    expect($mapper->mapMany(['hernia discal']))->toBe([BodyRegion::LowerBack]);
});

it('deduplicates when two different real values map to the same BodyRegion', function () {
    $mapper = new BodyRegionCanonicalMapper;

    expect($mapper->mapMany(['lesión de hombro', 'manguito rotador']))->toBe([BodyRegion::Shoulder]);
});

it('never invents a region for unrecognized text — no substring matching, no heuristics', function () {
    $mapper = new BodyRegionCanonicalMapper;

    // Ninguno de estos tiene correspondencia exacta con el catálogo,
    // aunque mencionen partes del cuerpo de forma parecida o parcial.
    expect($mapper->mapMany(['me duele el hombro']))->toBe([]);
    expect($mapper->mapMany(['hombro']))->toBe([]);
    expect($mapper->mapMany(['knee']))->toBe([]);
    expect($mapper->mapMany(['']))->toBe([]);
});

it('isRecognized() reports exactly whether a text is in the exact-match catalog', function () {
    $mapper = new BodyRegionCanonicalMapper;

    expect($mapper->isRecognized('hernia discal'))->toBeTrue();
    expect($mapper->isRecognized('me duele la espalda'))->toBeFalse();
    expect($mapper->isRecognized(123))->toBeFalse();
});
