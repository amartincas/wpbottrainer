<?php

use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\Enums\MediaVariant;
use App\ExerciseCatalog\ProviderRegistry;
use App\ExerciseCatalog\Providers\NullExerciseProvider;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseNormalizer;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseProvider;

/**
 * Hito 9.1: `provider` es un string simple validado por este registro
 * contra config('exercise_providers.*') — nunca un enum de dominio ni una
 * tabla `providers`. Agregar un proveedor nuevo es una entrada de config +
 * una implementación, verificado aquí indirectamente por el hecho de que
 * NullExerciseProvider satisface el mismo contrato sin estar registrado.
 */
it('resolves the real ymove provider registered in config', function () {
    $registry = new ProviderRegistry;

    expect($registry->has('ymove'))->toBeTrue();
    expect($registry->get('ymove'))->toBeInstanceOf(YMoveExerciseProvider::class);
    expect($registry->getNormalizer('ymove'))->toBeInstanceOf(YMoveExerciseNormalizer::class);
    expect($registry->knownKeys())->toContain('ymove');
});

it('throws for an unknown provider key instead of silently returning null', function () {
    $registry = new ProviderRegistry;

    expect($registry->has('musclewiki'))->toBeFalse();
    expect(fn () => $registry->get('musclewiki'))->toThrow(InvalidArgumentException::class);
});

/**
 * Prueba de que el contrato no tiene fugas hacia YMove: un segundo
 * implementador trivial, sin registrar en config, satisface la misma
 * interfaz completa sin conocer nada de YMove.
 */
it('lets a second, completely unrelated provider implementation satisfy the same contract', function () {
    $provider = new NullExerciseProvider;

    expect($provider->key())->toBe('null');
    expect($provider->search(new ProviderSearchCriteria))->toHaveCount(0);
    expect($provider->find('anything'))->toBeNull();
    expect($provider->resolveMedia('anything', MediaVariant::Default))->toBeNull();
    expect($provider->isAvailable())->toBeFalse();
    expect($provider->variants('anything'))->toBe([]);
    expect($provider->alternatives('anything'))->toBe([]);
});
