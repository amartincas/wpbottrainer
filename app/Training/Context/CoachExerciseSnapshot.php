<?php

namespace App\Training\Context;

use App\Training\Enums\HistoryExerciseOutcome;
use App\Training\Support\HistorySetEntry;

/**
 * Bloque 9 (D052) — un ejercicio de la sesión ACTUAL (pendiente o la más
 * reciente ya cerrada), para que Coach pueda responder "¿cómo me fue?"/
 * "¿por qué hago este ejercicio?"/"¿cuánto me falta?". Deliberadamente
 * distinto de `App\Training\Support\HistoryExerciseEntry` (D049): esa
 * clase representa una ejecución dentro de la ventana histórica cerrada
 * (Completed/Skipped, nunca la sesión Scheduled activa, por diseño de
 * D049) — mezclar ambos tipos confundiría esa frontera arquitectónica
 * deliberada. Reutiliza `HistorySetEntry`/`HistoryExerciseOutcome` (D049)
 * tal cual — son el vocabulario correcto para "una serie"/"el resultado de
 * un ejercicio" sin importar de qué sesión provienen.
 */
final readonly class CoachExerciseSnapshot
{
    /**
     * @param  array<int, HistorySetEntry>  $actualSets
     */
    public function __construct(
        public ?int $exerciseId,
        public string $name,
        public ?int $prescribedSets,
        public ?int $prescribedReps,
        public ?float $prescribedLoad,
        public ?int $prescribedDurationSeconds,
        public HistoryExerciseOutcome $outcome,
        public array $actualSets,
        public ?int $rpe,
        public ?string $note,
    ) {}
}
