<?php

namespace App\ExerciseCatalog\DTOs;

use App\Training\Enums\MuscleFocus;

/**
 * Hito 9.1 — criterios de búsqueda para SINCRONIZACIÓN del catálogo
 * (App\ExerciseCatalog\Importer\ExerciseImporter / el comando
 * `exercises:sync`), nunca usados por TrainingEngine directamente.
 * Expresados en NUESTRO vocabulario (MuscleFocus) — cada
 * ExerciseProviderInterface::search() traduce internamente al parámetro
 * propio del proveedor (ej. YMove: `muscleGroup=glutes`).
 */
final readonly class ProviderSearchCriteria
{
    public function __construct(
        public ?MuscleFocus $muscleFocus = null,
        public bool $hasVideoOnly = true,
        public ?int $page = null,
    ) {}
}
