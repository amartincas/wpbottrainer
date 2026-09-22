<?php

use App\Training\Support\RequestedFocusGroup;
use App\Training\Support\RequestedFocusTermMapper;

function requestedFocusGroupByKey(array $groups, string $key): ?RequestedFocusGroup
{
    foreach ($groups as $group) {
        if ($group->key === $key) {
            return $group;
        }
    }

    return null;
}

it('6: maps "pecho" to a single chest group', function () {
    $groups = (new RequestedFocusTermMapper)->mapMany(['pecho']);

    expect($groups)->toHaveCount(1);
    expect($groups[0]->key)->toBe('chest');
    expect($groups[0]->muscles)->toBe(['chest']);
});

it('7: maps "piernas" and all its exact synonyms to the same compound legs group, never duplicated', function (string $term) {
    $groups = (new RequestedFocusTermMapper)->mapMany([$term]);

    expect($groups)->toHaveCount(1);
    expect($groups[0]->key)->toBe('legs');
    expect($groups[0]->muscles)->toBe(['quads', 'hamstrings', 'glutes', 'calves']);
})->with(['piernas', 'pierna', 'tren inferior', 'lower body', 'lower']);

it('7b: combining two synonyms of "piernas" in the same request produces ONE group, not two', function () {
    $groups = (new RequestedFocusTermMapper)->mapMany(['piernas', 'pierna', 'lower']);

    expect($groups)->toHaveCount(1);
    expect($groups[0]->key)->toBe('legs');
});

it('8: maps "brazos" to a compound biceps+triceps group', function () {
    $groups = (new RequestedFocusTermMapper)->mapMany(['brazos']);

    expect($groups)->toHaveCount(1);
    expect($groups[0]->key)->toBe('arms');
    expect($groups[0]->muscles)->toBe(['biceps', 'triceps']);
});

it('9: "core" and "abdomen" both map to the same abs group, never duplicated when combined', function () {
    $core = (new RequestedFocusTermMapper)->mapMany(['core']);
    $abdomen = (new RequestedFocusTermMapper)->mapMany(['abdomen']);
    $both = (new RequestedFocusTermMapper)->mapMany(['core', 'abdomen']);

    expect($core[0]->key)->toBe('core');
    expect($core[0]->muscles)->toBe(['abs']);
    expect($abdomen[0]->key)->toBe('core');
    expect($abdomen[0]->muscles)->toBe(['abs']);
    expect($both)->toHaveCount(1);
});

it('10: unknown terms are rejected — never approximated, never invented', function () {
    $groups = (new RequestedFocusTermMapper)->mapMany(['biceps femoral inventado']);

    expect($groups)->toBeNull();
    expect((new RequestedFocusTermMapper)->isRecognized('biceps femoral inventado'))->toBeFalse();
});

it('11: never does substring matching — a superstring of a known term is rejected', function () {
    $mapper = new RequestedFocusTermMapper;

    // "piernas fuertes" CONTIENE "piernas" como substring, pero no es una
    // correspondencia EXACTA — debe rechazarse, igual que el patrón ya
    // establecido por BodyRegionCanonicalMapper.
    expect($mapper->mapMany(['piernas fuertes']))->toBeNull();
    expect($mapper->isRecognized('piernas fuertes'))->toBeFalse();

    // Mayúsculas distintas tampoco se aproximan (sin normalización, mismo
    // criterio que los otros canonical mappers del repo).
    expect($mapper->mapMany(['Piernas']))->toBeNull();
});

it('12: "todo el cuerpo"/"full body" represent the absence of a requested focus, never a group', function (string $term) {
    $groups = (new RequestedFocusTermMapper)->mapMany([$term]);

    expect($groups)->toBeNull();
    expect((new RequestedFocusTermMapper)->isRecognized($term))->toBeTrue();
})->with(['todo el cuerpo', 'full body']);

it('12b: "todo el cuerpo" mixed with a real term does not block the real term', function () {
    $groups = (new RequestedFocusTermMapper)->mapMany(['todo el cuerpo', 'pecho']);

    expect($groups)->toHaveCount(1);
    expect($groups[0]->key)->toBe('chest');
});

it('overlapping-but-distinct groups (e.g. "piernas" + "cuádriceps") are kept independent, never merged', function () {
    // Confirma la Sección 1 del diseño aprobado: distintos `key` nunca se
    // fusionan aunque sus `muscles` se solapen — a diferencia de los
    // sinónimos exactos del mismo key (test 7b).
    $groups = (new RequestedFocusTermMapper)->mapMany(['piernas', 'cuádriceps']);

    expect($groups)->toHaveCount(2);
    expect(requestedFocusGroupByKey($groups, 'legs')->muscles)->toBe(['quads', 'hamstrings', 'glutes', 'calves']);
    expect(requestedFocusGroupByKey($groups, 'quads')->muscles)->toBe(['quads']);
});

it('order of appearance in the raw terms is preserved in the resulting group array', function () {
    $groups = (new RequestedFocusTermMapper)->mapMany(['piernas', 'pecho']);

    expect($groups[0]->key)->toBe('legs');
    expect($groups[1]->key)->toBe('chest');
});

it('non-string terms in the input array are ignored without error', function () {
    $groups = (new RequestedFocusTermMapper)->mapMany(['pecho', 123, null, ['nested']]);

    expect($groups)->toHaveCount(1);
    expect($groups[0]->key)->toBe('chest');
});

it('an empty terms array produces no requested focus', function () {
    expect((new RequestedFocusTermMapper)->mapMany([]))->toBeNull();
});
