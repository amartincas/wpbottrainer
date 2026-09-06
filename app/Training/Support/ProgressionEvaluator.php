<?php

namespace App\Training\Support;

use App\Training\Enums\HistoryExerciseOutcome;
use App\Training\Enums\ProgressionDecision;
use App\Training\Enums\ProgressionEffortSignal;
use App\Training\Enums\ProgressionIntensitySignal;
use App\Training\Enums\TrackingType;

/**
 * Bloque 7 (D050) — evaluador determinista, sin IA y de SOLO LECTURA de la
 * DIRECCIÓN de progresión de un ejercicio específico (`progress`/`maintain`/
 * `reduce`/`insufficient_data`). Consume únicamente `TrainingHistoryContext`
 * (D049) — sin Eloquent, sin `App\Models\Exercise`, sin consultas SQL, sin
 * caché. NUNCA calcula cuánto peso añadir/reducir ni genera un
 * `WorkoutExercise`/`prescribed_*` — esa autoridad sigue siendo exclusiva de
 * `TrainingEngine`. No consulta `TrainingRestriction`/`SafetyRestrictionResolver`/
 * `DeclaredHealthCondition` ni ninguna lógica de anti-repetición: la
 * seguridad y la diversidad de ejercicios son responsabilidades ya resueltas
 * aguas arriba, no de este evaluador.
 *
 * PRINCIPIO DE EJECUCIÓN ÚNICA: se define E como la ejecución `Performed`
 * cronológicamente más reciente del ejercicio dentro de la ventana.
 * Intensidad, RPE y cumplimiento de reps de una decisión puntual proceden
 * SIEMPRE de esa misma E — nunca se combinan datos de ejecuciones distintas
 * como si fueran una sola evidencia. Ejecuciones anteriores a E solo se usan
 * para comparar (`bestPriorIntensity`), nunca para sustituir un dato
 * ausente de E.
 *
 * Depende de que `TrainingHistoryContext.sessions` venga ordenado de más
 * reciente a más antiguo (`scheduled_at` DESC) — garantizado por
 * `TrainingHistoryContextProvider` (D049), no un supuesto propio.
 *
 * REASON CODES (conjunto cerrado, uno primario por decisión + hasta 3
 * informativos que nunca cambian la decisión):
 * - `no_history`, `single_execution_no_data`, `single_execution_baseline`,
 *   `no_usable_signals` — gate de evidencia (insufficient_data/maintain).
 * - `high_effort_with_shortfall` — única vía a `reduce`.
 * - `consistent_controlled_performance_improved`,
 *   `consistent_controlled_performance_at_best`,
 *   `reps_target_met_no_load_signal` — vías a `progress`.
 * - `contradictory_signals`, `below_recent_best_no_corroboration`,
 *   `reps_below_prescribed_no_corroboration`,
 *   `elevated_effort_stable_performance`, `default_conservative` — vías a
 *   `maintain` sin corroboración suficiente para cambiar de dirección.
 * - `prescribed_reps_missing`, `reps_data_missing`,
 *   `fewer_sets_than_prescribed`, `reps_below_prescribed`,
 *   `reps_met_or_exceeded` — diagnóstico del cumplimiento de reps de E,
 *   informativo, adjuntado junto al código primario.
 * - `intensity_unknown_last_execution` — informativo, cuando E no tiene
 *   dato de intensidad.
 * - `most_recent_execution_skipped` — informativo, cuando la ejecución
 *   cronológicamente más reciente de CUALQUIER resultado fue `Skipped`
 *   (es decir, E es en realidad una ejecución anterior a esa). Nunca
 *   penaliza ni cambia la decisión — un salto se trata como dato
 *   puramente informativo, nunca como fallo deportivo ni señal médica.
 */
final class ProgressionEvaluator
{
    private const EFFORT_CONTROLLED_MAX = 6;

    private const EFFORT_ELEVATED_MAX = 8;

    public function evaluate(TrainingHistoryContext $context, int $exerciseId, TrackingType $trackingType): ProgressionEvaluation
    {
        $matchingEntries = $this->matchingEntriesNewestFirst($context, $exerciseId);

        $performedEntries = array_values(array_filter(
            $matchingEntries,
            fn (HistoryExerciseEntry $entry) => $entry->outcome === HistoryExerciseOutcome::Performed,
        ));

        $mostRecentEntryOverall = $matchingEntries[0] ?? null;
        $mostRecentWasSkipped = $mostRecentEntryOverall?->outcome === HistoryExerciseOutcome::Skipped;

        $executionsConsidered = count($performedEntries);
        $e = $performedEntries[0] ?? null;
        $priorPerformed = array_slice($performedEntries, 1);

        $lastIntensity = $e !== null ? $this->intensityOf($e, $trackingType) : null;

        $priorIntensities = array_values(array_filter(
            array_map(fn (HistoryExerciseEntry $prior) => $this->intensityOf($prior, $trackingType), $priorPerformed),
            fn (?float $value) => $value !== null,
        ));
        $bestPriorIntensity = $priorIntensities === [] ? null : max($priorIntensities);

        $intensitySignal = $this->intensitySignal($lastIntensity, $bestPriorIntensity);
        $effortSignal = $this->effortSignal($e?->rpe);
        [$repsMet, $complianceReason] = $this->repsCompliance($e);

        $metrics = new ProgressionMetrics(
            executionsConsidered: $executionsConsidered,
            intensitySignal: $intensitySignal,
            lastIntensity: $lastIntensity,
            bestPriorIntensity: $bestPriorIntensity,
            effortSignal: $effortSignal,
            lastExecutionRpe: $e?->rpe,
            repsMetOnMostRecent: $repsMet,
        );

        [$decision, $primaryReason] = $this->decide($executionsConsidered, $intensitySignal, $effortSignal, $repsMet);

        $reasonCodes = [$primaryReason];

        if ($complianceReason !== null) {
            $reasonCodes[] = $complianceReason;
        }

        if ($intensitySignal === ProgressionIntensitySignal::Unknown) {
            $reasonCodes[] = 'intensity_unknown_last_execution';
        }

        if ($mostRecentWasSkipped) {
            $reasonCodes[] = 'most_recent_execution_skipped';
        }

        return new ProgressionEvaluation(
            exerciseId: $exerciseId,
            decision: $decision,
            reasonCodes: $reasonCodes,
            metrics: $metrics,
            evaluatedAt: now(),
            sourceWindowSessions: $context->windowSessionsCount,
            sourceWindowWeeks: $context->windowWeeks,
        );
    }

    /**
     * @return array<int, HistoryExerciseEntry> ordenado igual que
     *         `TrainingHistoryContext.sessions` (más reciente primero)
     */
    private function matchingEntriesNewestFirst(TrainingHistoryContext $context, int $exerciseId): array
    {
        $entries = [];

        foreach ($context->sessions as $session) {
            foreach ($session->exercises as $exerciseEntry) {
                if ($exerciseEntry->exerciseId === $exerciseId) {
                    $entries[] = $exerciseEntry;
                }
            }
        }

        return $entries;
    }

    /**
     * Intensidad de UNA ejecución: carga (RepsAndLoad) o duración
     * (TimeBased) — nunca ambas, nunca mezcladas. Mismo criterio de
     * "máximo entre sets con dato" que D049 ya usa para carga, replicado
     * aquí para duración sin modificar `HistoryAggregates`.
     */
    private function intensityOf(HistoryExerciseEntry $entry, TrackingType $trackingType): ?float
    {
        $values = match ($trackingType) {
            TrackingType::RepsAndLoad => array_map(fn (HistorySetEntry $set) => $set->load, $entry->sets),
            TrackingType::TimeBased => array_map(
                fn (HistorySetEntry $set) => $set->durationSeconds !== null ? (float) $set->durationSeconds : null,
                $entry->sets,
            ),
        };

        $values = array_values(array_filter($values, fn (?float $value) => $value !== null));

        return $values === [] ? null : max($values);
    }

    private function intensitySignal(?float $lastIntensity, ?float $bestPriorIntensity): ProgressionIntensitySignal
    {
        if ($lastIntensity !== null && $bestPriorIntensity !== null) {
            return match (true) {
                $lastIntensity > $bestPriorIntensity => ProgressionIntensitySignal::Improved,
                $lastIntensity < $bestPriorIntensity => ProgressionIntensitySignal::BelowRecentBest,
                default => ProgressionIntensitySignal::AtRecentBest,
            };
        }

        if ($lastIntensity === null && $bestPriorIntensity === null) {
            return ProgressionIntensitySignal::NotApplicable;
        }

        // Exactamente uno de los dos está presente: o E no tiene dato (sin
        // buscar hacia atrás), o E sí lo tiene pero no hay línea base previa.
        return ProgressionIntensitySignal::Unknown;
    }

    private function effortSignal(?int $rpe): ProgressionEffortSignal
    {
        return match (true) {
            $rpe === null => ProgressionEffortSignal::Unknown,
            $rpe <= self::EFFORT_CONTROLLED_MAX => ProgressionEffortSignal::Controlled,
            $rpe <= self::EFFORT_ELEVATED_MAX => ProgressionEffortSignal::Elevated,
            default => ProgressionEffortSignal::Excessive,
        };
    }

    /**
     * Cumplimiento de reps, evaluado ÚNICAMENTE sobre E. Nunca imputa
     * `actual_reps` ni `prescribedReps` ausentes.
     *
     * @return array{0: ?bool, 1: ?string} [Met=true/Below=false/Unknown=null, reason]
     */
    private function repsCompliance(?HistoryExerciseEntry $e): array
    {
        if ($e === null) {
            return [null, null];
        }

        if ($e->prescribedReps === null) {
            return [null, 'prescribed_reps_missing'];
        }

        $actualReps = array_values(array_filter(
            array_map(fn (HistorySetEntry $set) => $set->reps, $e->sets),
            fn (?int $reps) => $reps !== null,
        ));

        if ($actualReps === []) {
            return [null, 'reps_data_missing'];
        }

        if ($e->prescribedSets !== null && count($e->sets) < $e->prescribedSets) {
            return [false, 'fewer_sets_than_prescribed'];
        }

        $minReps = min($actualReps);

        return $minReps >= $e->prescribedReps
            ? [true, 'reps_met_or_exceeded']
            : [false, 'reps_below_prescribed'];
    }

    /**
     * Tabla de decisión, evaluada en este orden exacto — la primera regla
     * que aplica gana. `reduce` es exclusivamente la regla 4: nunca se
     * origina por `skip_reason` ni por ningún dato de seguridad/salud.
     *
     * @return array{0: ProgressionDecision, 1: string}
     */
    private function decide(
        int $executionsConsidered,
        ProgressionIntensitySignal $intensity,
        ProgressionEffortSignal $effort,
        ?bool $repsMet,
    ): array {
        $intensityUsable = in_array($intensity, [
            ProgressionIntensitySignal::Improved,
            ProgressionIntensitySignal::AtRecentBest,
            ProgressionIntensitySignal::BelowRecentBest,
        ], true);

        $hasUsableSignal = $intensityUsable || $effort !== ProgressionEffortSignal::Unknown || $repsMet !== null;

        if ($executionsConsidered === 0) {
            return [ProgressionDecision::InsufficientData, 'no_history'];
        }

        if ($executionsConsidered === 1) {
            return $hasUsableSignal
                ? [ProgressionDecision::Maintain, 'single_execution_baseline']
                : [ProgressionDecision::InsufficientData, 'single_execution_no_data'];
        }

        if (! $hasUsableSignal) {
            return [ProgressionDecision::InsufficientData, 'no_usable_signals'];
        }

        if ($effort === ProgressionEffortSignal::Excessive
            && ($intensity === ProgressionIntensitySignal::BelowRecentBest || $repsMet === false)) {
            return [ProgressionDecision::Reduce, 'high_effort_with_shortfall'];
        }

        if (in_array($intensity, [ProgressionIntensitySignal::Improved, ProgressionIntensitySignal::AtRecentBest], true)
            && in_array($effort, [ProgressionEffortSignal::Controlled, ProgressionEffortSignal::Unknown], true)
            && ($repsMet === true || $repsMet === null)) {
            return [
                ProgressionDecision::Progress,
                $intensity === ProgressionIntensitySignal::Improved
                    ? 'consistent_controlled_performance_improved'
                    : 'consistent_controlled_performance_at_best',
            ];
        }

        if ($intensity === ProgressionIntensitySignal::NotApplicable
            && $repsMet === true
            && in_array($effort, [ProgressionEffortSignal::Controlled, ProgressionEffortSignal::Unknown], true)) {
            return [ProgressionDecision::Progress, 'reps_target_met_no_load_signal'];
        }

        if ($effort === ProgressionEffortSignal::Excessive) {
            return [ProgressionDecision::Maintain, 'contradictory_signals'];
        }

        if ($intensity === ProgressionIntensitySignal::BelowRecentBest) {
            return [ProgressionDecision::Maintain, 'below_recent_best_no_corroboration'];
        }

        if ($repsMet === false) {
            return [ProgressionDecision::Maintain, 'reps_below_prescribed_no_corroboration'];
        }

        if ($effort === ProgressionEffortSignal::Elevated) {
            return [ProgressionDecision::Maintain, 'elevated_effort_stable_performance'];
        }

        return [ProgressionDecision::Maintain, 'default_conservative'];
    }
}
