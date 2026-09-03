<?php

namespace App\ExerciseCatalog\DTOs;

use Illuminate\Support\Collection;

/**
 * Hito 9.3 — igual que una búsqueda normal, pero exponiendo la paginación
 * REAL que el proveedor reporta. `search()` (usado por import puntual/
 * selectivo) no necesita esto — solo una sincronización COMPLETA del
 * catálogo, que debe distinguir "llegué al final real" de "esta página
 * vino vacía por un error", lo necesita (ver ExerciseImporter::fullSync()).
 *
 * `totalPages`/`totalItems` son `null` si el proveedor no los reporta —
 * nunca se asume un valor, el llamador debe decidir qué hacer con esa
 * ausencia (YMove sí los reporta siempre, auditado).
 */
final readonly class ProviderSearchPage
{
    /** @param  Collection<int, ProviderExerciseData>  $items */
    public function __construct(
        public Collection $items,
        public int $page,
        public ?int $totalPages,
        public ?int $totalItems,
    ) {}
}
