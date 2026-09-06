<?php

namespace App\Training\Support;

use App\Training\Enums\ProgressionEffortSignal;
use App\Training\Enums\ProgressionIntensitySignal;

/**
 * Bloque 7 (D050) — hechos estructurados usados por `ProgressionEvaluator`
 * para decidir, todos derivados de la ejecución `Performed` cronológicamente
 * más reciente (E) salvo `executionsConsidered` y `bestPriorIntensity`
 * (explícitamente anteriores a E). Ningún campo es una recomendación
 * numérica ni texto libre.
 */
final readonly class ProgressionMetrics
{
    public function __construct(
        public int $executionsConsidered,
        public ProgressionIntensitySignal $intensitySignal,
        public ?float $lastIntensity,
        public ?float $bestPriorIntensity,
        public ProgressionEffortSignal $effortSignal,
        public ?int $lastExecutionRpe,
        public ?bool $repsMetOnMostRecent,
    ) {}
}
