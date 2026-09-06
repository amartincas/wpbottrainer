<?php

namespace App\Training\Support;

use App\Training\Enums\WorkoutSessionStatus;
use Carbon\CarbonInterface;

/**
 * Bloque 6 — un `WorkoutSession` dentro de la ventana histórica.
 *
 * `decidedFocus`/`goal` vienen ÚNICAMENTE de
 * `WorkoutSession.prescription_context_snapshot` (Bloque 3) — nunca del
 * `TrainingProfile` actual, que puede haber cambiado desde entonces. En
 * sesiones anteriores al Bloque 3 (`prescription_context_snapshot === null`)
 * ambos quedan en `null` — nunca se inventan ni se aproximan.
 *
 * `effectiveDate`: `completed_at` si existe; si no, la fecha más temprana
 * entre los `logged_at` de sus ejercicios; si tampoco, `scheduled_at`
 * (garantizado no nulo). Nunca se introduce un campo `started_at` nuevo.
 */
final readonly class HistorySessionEntry
{
    /**
     * @param  array<int, HistoryExerciseEntry>  $exercises
     */
    public function __construct(
        public int $workoutSessionId,
        public WorkoutSessionStatus $status,
        public CarbonInterface $effectiveDate,
        public CarbonInterface $scheduledAt,
        public ?CarbonInterface $completedAt,
        public ?string $decidedFocus,
        public ?string $goal,
        public array $exercises,
    ) {}
}
