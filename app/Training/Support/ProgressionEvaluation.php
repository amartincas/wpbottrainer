<?php

namespace App\Training\Support;

use App\Training\Enums\ProgressionDecision;
use Carbon\CarbonInterface;

/**
 * Bloque 7 (D050) — resultado de `ProgressionEvaluator::evaluate()`. Decisión
 * estructurada y trazable, nunca texto libre ni un número de prescripción:
 * `TrainingEngine` sigue siendo quien traduce esta dirección en valores
 * concretos (sets/reps/carga/duración).
 */
final readonly class ProgressionEvaluation
{
    /**
     * @param  array<int, string>  $reasonCodes  conjunto cerrado, documentado
     *         en el docblock de `ProgressionEvaluator`
     */
    public function __construct(
        public int $exerciseId,
        public ProgressionDecision $decision,
        public array $reasonCodes,
        public ProgressionMetrics $metrics,
        public CarbonInterface $evaluatedAt,
        public int $sourceWindowSessions,
        public int $sourceWindowWeeks,
    ) {}
}
