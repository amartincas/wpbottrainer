<?php

namespace App\ExerciseCatalog\DTOs;

/**
 * Hito 9.1 — shape "crudo" devuelto por un Adapter, todavía en el
 * vocabulario propio del proveedor. `raw` es deliberadamente un array
 * opaco (`array<string, mixed>`) — solo el ExerciseNormalizerInterface del
 * MISMO proveedor sabe interpretarlo. Nada fuera del par
 * Adapter+Normalizer de un proveedor debería leer `raw` directamente.
 */
final readonly class ProviderExerciseData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $providerExerciseId,
        public array $raw,
    ) {}
}
