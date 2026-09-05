<?php

use App\Training\Support\FunctionalLimitationCanonicalMapper;

it('the catalog starts empty by design — no medical phrase list invented', function () {
    $reflection = new ReflectionClass(FunctionalLimitationCanonicalMapper::class);

    expect($reflection->getConstant('EXACT_MAP'))->toBe([]);
});

it('5: an unrecognized functional-limitation phrase returns null', function () {
    expect((new FunctionalLimitationCanonicalMapper)->map('no puedo hacer burpees'))->toBeNull();
});

it('6: no fuzzy matching — a similar but uncatalogued phrase still returns null', function () {
    expect((new FunctionalLimitationCanonicalMapper)->map('no logro levantar el brazo por encima de la cabeza'))->toBeNull();
    // Una coma, un punto o una mayúscula distinta ya es una cadena distinta
    // — coincidencia EXACTA, nunca aproximada.
    expect((new FunctionalLimitationCanonicalMapper)->map('No puedo levantar el brazo por encima de la cabeza.'))->toBeNull();
});

it('isRecognized() mirrors map() — false for anything not in the closed catalog', function () {
    expect((new FunctionalLimitationCanonicalMapper)->isRecognized('cualquier frase'))->toBeFalse();
    expect((new FunctionalLimitationCanonicalMapper)->isRecognized(123))->toBeFalse();
});
