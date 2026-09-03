<?php

namespace App\ExerciseCatalog\DTOs;

/**
 * Hito 9.3 — reporte de `ExerciseImporter::fullSync()`. `completedFully`
 * es la señal clave: en `false`, `possiblyRemoved` está SIEMPRE vacío —
 * una corrida interrumpida por un error real de la API nunca reconcilia
 * bajas (ver docs/DECISIONS.md: nunca tratar una respuesta incompleta
 * como desaparición real del proveedor).
 */
final readonly class FullSyncResult
{
    /**
     * @param  string[]  $possiblyRemoved  provider_exercise_id desactivados en esta corrida
     */
    public function __construct(
        public int $totalReceived,
        public int $created,
        public int $updated,
        public int $unchanged,
        public array $possiblyRemoved,
        public int $pagesProcessed,
        public bool $completedFully,
        public ?string $errorMessage,
    ) {}
}
