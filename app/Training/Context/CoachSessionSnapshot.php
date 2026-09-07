<?php

namespace App\Training\Context;

use App\Training\Enums\WorkoutSessionStatus;
use Carbon\CarbonInterface;

/**
 * Bloque 9 (D052) — la sesión ACTUAL: la `WorkoutSession` `Scheduled`
 * pendiente si existe; si no, la `Completed`/`Skipped` más reciente; `null`
 * si el contacto nunca entrenó. D049 excluye deliberadamente la sesión
 * activa de `TrainingHistoryContext` — esta clase es, a propósito, el
 * complemento exacto de esa exclusión, nunca una reconstrucción de la
 * ventana histórica (`TrainingHistoryContextProvider` no se modifica ni se
 * duplica su lógica de ventana).
 *
 * Sin `effectiveDate`: a diferencia de `HistorySessionEntry` (D049, pensada
 * para sesiones ya cerradas), una sesión `Scheduled` no tiene un concepto
 * significativo de "fecha efectiva" todavía — se exponen `scheduledAt`/
 * `completedAt` tal cual, sin inventar un fallback.
 */
final readonly class CoachSessionSnapshot
{
    /**
     * @param  array<int, CoachExerciseSnapshot>  $exercises
     */
    public function __construct(
        public int $workoutSessionId,
        public WorkoutSessionStatus $status,
        public ?string $decidedFocus,
        public ?string $goal,
        public CarbonInterface $scheduledAt,
        public ?CarbonInterface $completedAt,
        public array $exercises,
    ) {}
}
