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
 *
 * Hito 9.3 — `hasVideo` es tri-estado, no un booleano: `null` = sin
 * filtro (trae todo el catálogo, con y sin video — el modo que usa el
 * inventario de referencia completo); `true`/`false` filtran
 * explícitamente. Antes era `hasVideoOnly: bool = true` (siempre
 * filtraba); el default cambió a `null` porque `search()`/`searchPaged()`
 * ahora sirven tanto al import selectivo (que sí quiere solo-con-video)
 * como al inventario completo (que no quiere ningún filtro) — cada
 * llamador pasa el valor que le corresponde explícitamente.
 */
final readonly class ProviderSearchCriteria
{
    public function __construct(
        public ?MuscleFocus $muscleFocus = null,
        public ?bool $hasVideo = null,
        public ?int $page = null,
    ) {}
}
