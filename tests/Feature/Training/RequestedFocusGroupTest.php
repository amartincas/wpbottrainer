<?php

use App\Training\Support\RequestedFocusGroup;

// Hito B1 (Requested Focus) — RequestedFocusGroup es un value object puro
// (sin BD), pero se testea bajo tests/Feature/Training/ por la misma
// convención ya usada en este repo para clases de dominio sin persistencia
// (ver BodyRegionCanonicalMapperTest.php) — no existe tests/Unit aquí.

it('1: represents a simple single-muscle group', function () {
    $group = new RequestedFocusGroup('chest', ['chest']);

    expect($group->key)->toBe('chest');
    expect($group->muscles)->toBe(['chest']);
});

it('2: represents a compound group with several muscles under one key', function () {
    $group = new RequestedFocusGroup('legs', ['quads', 'hamstrings', 'glutes', 'calves']);

    expect($group->key)->toBe('legs');
    expect($group->muscles)->toBe(['quads', 'hamstrings', 'glutes', 'calves']);
});

it('3: rejects duplicate muscles within the same group', function () {
    new RequestedFocusGroup('legs', ['quads', 'quads', 'hamstrings']);
})->throws(InvalidArgumentException::class);

it('4: key is a plain canonical string, never a free-text label', function () {
    // No hay transformación/normalización en el value object — la
    // canonicalización es responsabilidad de RequestedFocusTermMapper, no
    // de esta clase (ver su docblock). Este test documenta esa frontera:
    // el value object confía en que el key ya llega canónico.
    $group = new RequestedFocusGroup('legs', ['quads']);

    expect($group->key)->toBeString();
    expect($group->key)->toBe('legs');
});

it('5: rejects a group with no muscles at all', function () {
    new RequestedFocusGroup('legs', []);
})->throws(InvalidArgumentException::class);
