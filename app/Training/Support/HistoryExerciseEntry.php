<?php

namespace App\Training\Support;

use App\Training\Enums\HistoryExerciseOutcome;
use App\Training\Enums\SkipReason;
use Carbon\CarbonInterface;

/**
 * Bloque 6 — un `WorkoutExercise` dentro del historial. `name` viene SIEMPRE
 * de `exercise_snapshot` (nunca del `Exercise` en vivo) — sigue existiendo
 * aunque `exerciseId` sea `null` (Exercise borrado) o el ejercicio actual
 * esté `is_active=false`: el snapshot histórico manda, el catálogo actual
 * no participa en absoluto en su reconstrucción.
 */
final readonly class HistoryExerciseEntry
{
    /**
     * @param  array<int, HistorySetEntry>  $sets
     */
    public function __construct(
        public ?int $exerciseId,
        public string $name,
        public HistoryExerciseOutcome $outcome,
        public array $sets,
        public ?int $rpe,
        public ?string $note,
        public ?SkipReason $skipReason,
        public ?CarbonInterface $loggedAt,
    ) {}
}
