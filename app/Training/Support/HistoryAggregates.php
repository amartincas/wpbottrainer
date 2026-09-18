<?php

namespace App\Training\Support;

use Carbon\CarbonInterface;

/**
 * Bloque 6 — métricas deterministas sobre la ventana histórica, indexadas
 * por `exercise_id` (nunca por nombre/snapshot) para que un futuro
 * `ProgressionEvaluator` pueda consultar "¿qué sé de este ejercicio?"
 * directamente, sin recorrer todo el historial de nuevo.
 *
 * Ningún campo aquí es una decisión ni una recomendación — son hechos ya
 * ocurridos. Deliberadamente NO existe un porcentaje de adherencia: el
 * modelo actual no escribe `WorkoutSessionStatus::Skipped` en ningún punto
 * del código (verificado), así que no hay un denominador confiable de
 * "sesiones esperadas" — ver docs/DECISIONS.md D049.
 *
 * Semántica exacta de los mapas de carga (misma convención que
 * `TrainingEngine::progressionFor()` ya usa para "el set más pesado"):
 * - Un `exercise_id` está AUSENTE del mapa (nunca `0`/`null` como valor)
 *   cuando el ejercicio es por duración, o cuando ninguna ejecución
 *   relevante tiene `actual_load` no nulo.
 * - `0` es un valor real y se preserva tal cual — nunca se confunde con
 *   "sin dato".
 * - `lastLoadByExerciseId` NUNCA busca hacia atrás: si la ejecución más
 *   reciente no tiene carga, el ejercicio simplemente no aparece.
 */
final readonly class HistoryAggregates
{
    /**
     * @param  array<int, float>  $lastLoadByExerciseId
     * @param  array<int, float>  $bestRecentLoadByExerciseId
     * @param  array<int, array{min: int, max: int}>  $recentRepRange
     * @param  array<int, CarbonInterface>  $lastPerformedAtByExerciseId
     * @param  array<int, int>  $exercisesRepeatedInWindow
     */
    public function __construct(
        /**
         * Hito — Historial de progreso por período (corrección post-E2E):
         * este número es el conteo de sesiones `Completed` DENTRO de la
         * colección ya acotada por `MAX_SESSIONS`/`WINDOW_WEEKS` de
         * `TrainingHistoryContextProvider` (contexto para razonamiento) —
         * NUNCA el total real de sesiones completadas en la ventana. Si un
         * contacto tiene más de `MAX_SESSIONS` sesiones completadas dentro
         * de esas semanas, este campo queda capado en `MAX_SESSIONS` y NO
         * refleja el total. Para un conteo real, sin `LIMIT`, usar
         * `App\Training\Support\TrainingSessionMetrics::completedCount()`.
         * `CoachFactsFormatter` ya NO expone este campo al LLM como si
         * fuera el conteo real — ver `formatPeriodMetrics()`.
         */
        public int $sessionsCompletedInWindow,
        public array $lastLoadByExerciseId,
        public array $bestRecentLoadByExerciseId,
        public array $recentRepRange,
        public array $lastPerformedAtByExerciseId,
        public array $exercisesRepeatedInWindow,
        public ?int $daysSinceLastCompletedSession,
    ) {}
}
