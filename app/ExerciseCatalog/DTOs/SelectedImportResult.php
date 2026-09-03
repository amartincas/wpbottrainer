<?php

namespace App\ExerciseCatalog\DTOs;

use App\Models\Exercise;
use Illuminate\Support\Collection;

/**
 * Hito 9.3 — resultado de `ExerciseImporter::importSelected()`: reporte
 * explícito de qué de la lista objetivo se encontró/importó y qué no,
 * nunca silencioso. Ver docs/DECISIONS.md.
 */
final readonly class SelectedImportResult
{
    /**
     * @param  Collection<int, Exercise>  $imported
     * @param  string[]  $notFound  provider_exercise_id pedidos que no aparecieron en ninguna página consultada
     * @param  string[]  $duplicatesInRequest  provider_exercise_id repetidos en la lista objetivo de entrada
     */
    public function __construct(
        public Collection $imported,
        public array $notFound,
        public array $duplicatesInRequest,
        public int $pagesConsulted,
    ) {}
}
