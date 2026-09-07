<?php

namespace App\Training\Support;

use App\Training\Context\CoachContext;
use App\Training\Context\CoachExerciseSnapshot;
use App\Training\Enums\HistoryExerciseOutcome;

/**
 * Bloque 9 (D052) — traduce `CoachContext` a un bloque de texto de HECHOS
 * para el prompt de Coach/`ExecutionReportService`. 100% determinista, sin
 * IA: cada `reasonCode` cerrado de `ProgressionEvaluator` (D050) se traduce
 * aquí a una frase corta ya aprobada — el LLM nunca ve el nombre crudo del
 * enum ni lo interpreta por su cuenta, solo teje estas frases en una
 * explicación natural. Fechas ya vienen formateadas por código (el LLM
 * nunca hace aritmética de fechas). Reutilizada por `CoachService` y por
 * `ExecutionReportService` (evolucionado) para no duplicar esta traducción
 * en dos lugares.
 */
class CoachFactsFormatter
{
    /**
     * Traducción cerrada de cada reason code de D050 a una frase en
     * español ya aprobada. Si D050 agregara un reason code nuevo sin
     * actualizar esta tabla, `translateReasonCode()` degrada a un texto
     * genérico — nunca a un error ni al nombre crudo del enum.
     */
    private const REASON_CODE_PHRASES = [
        'no_history' => 'no hay ejecuciones registradas todavía para este ejercicio',
        'single_execution_no_data' => 'solo existe una ejecución registrada y no tiene datos suficientes',
        'single_execution_baseline' => 'solo existe una ejecución registrada, suficiente para confirmar el punto de partida pero no para cambiar de dirección',
        'no_usable_signals' => 'hay varias ejecuciones registradas pero ninguna con datos suficientes para decidir',
        'high_effort_with_shortfall' => 'el esfuerzo percibido fue alto y además hubo un déficit real de rendimiento',
        'consistent_controlled_performance_improved' => 'la última ejecución superó el mejor rendimiento reciente con un esfuerzo controlado',
        'consistent_controlled_performance_at_best' => 'la última ejecución sostuvo el mejor rendimiento reciente con un esfuerzo controlado',
        'reps_target_met_no_load_signal' => 'se cumplieron las repeticiones previstas en un ejercicio sin carga registrada',
        'contradictory_signals' => 'el esfuerzo percibido fue alto, pero el rendimiento se mantuvo o mejoró',
        'below_recent_best_no_corroboration' => 'la última ejecución quedó por debajo del mejor rendimiento reciente, sin otra señal que lo confirme',
        'reps_below_prescribed_no_corroboration' => 'las repeticiones quedaron por debajo de lo prescrito, sin otra señal que lo confirme',
        'elevated_effort_stable_performance' => 'el esfuerzo percibido fue elevado pero el rendimiento se mantuvo estable',
        'default_conservative' => 'no hay evidencia suficiente para cambiar de dirección',
        'prescribed_reps_missing' => 'no hay una prescripción de repeticiones registrada para comparar',
        'reps_data_missing' => 'no hay repeticiones registradas para esa ejecución',
        'fewer_sets_than_prescribed' => 'se realizaron menos series de las prescritas',
        'reps_below_prescribed' => 'las repeticiones realizadas quedaron por debajo de las prescritas',
        'reps_met_or_exceeded' => 'las repeticiones prescritas se cumplieron o se superaron',
        'intensity_unknown_last_execution' => 'la última ejecución no tiene dato de carga/duración registrado',
        'most_recent_execution_skipped' => 'la ejecución más reciente fue omitida, sin que eso afecte esta evaluación',
    ];

    private const DECISION_LABELS = [
        'progress' => 'se progresa',
        'maintain' => 'se mantiene',
        'reduce' => 'se reduce la intensidad',
        'insufficient_data' => 'no hay datos suficientes para decidir una dirección',
    ];

    private const OUTCOME_LABELS = [
        'performed' => 'realizado',
        'skipped' => 'omitido',
        'unreported' => 'sin reportar',
    ];

    public function format(CoachContext $context): string
    {
        $lines = [];

        $lines[] = $this->formatProfile($context);
        $lines[] = $this->formatCurrentSession($context);
        $lines[] = $this->formatProgressions($context);
        $lines[] = $this->formatHistoryAggregates($context);

        $safetyRegions = $context->historyContext->activeSafetyBodyRegions;
        if ($safetyRegions !== []) {
            $lines[] = 'RESTRICCIONES DE SEGURIDAD YA CONFIRMADAS (informativo, no las reinterpretes ni las expliques como diagnóstico): '.implode(', ', $safetyRegions);
        }

        return implode("\n\n", array_filter($lines, fn (string $line) => trim($line) !== ''));
    }

    private function formatProfile(CoachContext $context): string
    {
        $profile = $context->profileSnapshot;

        $parts = [];
        if (! empty($profile['goal'])) {
            $parts[] = "objetivo={$profile['goal']}";
        }
        if (! empty($profile['experience_level'])) {
            $parts[] = "nivel={$profile['experience_level']}";
        }
        if (! empty($profile['primary_focus'])) {
            $parts[] = 'foco_primario='.implode(',', $profile['primary_focus']);
        }

        return $parts === [] ? '' : 'PERFIL: '.implode('; ', $parts);
    }

    private function formatCurrentSession(CoachContext $context): string
    {
        $session = $context->currentSession;

        if ($session === null) {
            return 'SESIÓN ACTUAL: el usuario todavía no tiene ninguna sesión registrada.';
        }

        $lines = [
            "SESIÓN ACTUAL (id={$session->workoutSessionId}, estado={$session->status->value}".
            ($session->decidedFocus !== null ? ", foco={$session->decidedFocus}" : '').
            ", programada_el={$session->scheduledAt->toDateString()}".
            ($session->completedAt !== null ? ", completada_el={$session->completedAt->toDateString()}" : '').
            '):',
        ];

        foreach ($session->exercises as $exercise) {
            $lines[] = '- '.$this->formatExerciseLine($exercise);
        }

        return implode("\n", $lines);
    }

    private function formatExerciseLine(CoachExerciseSnapshot $exercise): string
    {
        $outcomeLabel = self::OUTCOME_LABELS[$exercise->outcome->value] ?? $exercise->outcome->value;

        $prescribed = $exercise->prescribedDurationSeconds !== null
            ? "{$exercise->prescribedSets}x{$exercise->prescribedDurationSeconds}s"
            : "{$exercise->prescribedSets}x{$exercise->prescribedReps}".($exercise->prescribedLoad !== null ? "@{$exercise->prescribedLoad}kg" : '');

        $line = "{$exercise->name} (id={$exercise->exerciseId}) — prescrito: {$prescribed} — estado: {$outcomeLabel}";

        if ($exercise->outcome === HistoryExerciseOutcome::Performed) {
            $setsText = collect($exercise->actualSets)->map(function (HistorySetEntry $set) {
                if ($set->durationSeconds !== null) {
                    return "{$set->durationSeconds}s";
                }

                $load = $set->load !== null ? "@{$set->load}kg" : '';

                return "{$set->reps}rep{$load}";
            })->implode(', ');

            $line .= " — ejecutado: {$setsText}";

            if ($exercise->rpe !== null) {
                $line .= " — RPE={$exercise->rpe}";
            }
        }

        return $line;
    }

    private function formatProgressions(CoachContext $context): string
    {
        if ($context->progressionEvaluations === []) {
            return '';
        }

        $lines = ['EVALUACIÓN DE PROGRESIÓN POR EJERCICIO (ya decidida por el sistema, nunca la reinterpretes ni la cambies):'];

        foreach ($context->progressionEvaluations as $exerciseId => $evaluation) {
            $decisionLabel = self::DECISION_LABELS[$evaluation->decision->value] ?? $evaluation->decision->value;
            $reasons = implode('; ', array_map(fn (string $code) => $this->translateReasonCode($code), $evaluation->reasonCodes));

            $lines[] = "- ejercicio id={$exerciseId}: {$decisionLabel}. Motivo: {$reasons}.";
        }

        return implode("\n", $lines);
    }

    private function formatHistoryAggregates(CoachContext $context): string
    {
        $aggregates = $context->historyContext->aggregates;
        $lines = ['HISTORIAL RECIENTE (ventana de '.$context->historyContext->windowSessionsCount.' sesiones / '.$context->historyContext->windowWeeks.' semanas):'];

        $lines[] = "- sesiones completadas en la ventana: {$aggregates->sessionsCompletedInWindow}";

        $lines[] = $aggregates->daysSinceLastCompletedSession !== null
            ? "- última sesión completada: hace {$aggregates->daysSinceLastCompletedSession} día(s)"
            : '- última sesión completada: no hay ninguna en la ventana';

        foreach ($aggregates->lastLoadByExerciseId as $exerciseId => $lastLoad) {
            $best = $aggregates->bestRecentLoadByExerciseId[$exerciseId] ?? null;
            $lines[] = "- ejercicio id={$exerciseId}: última carga registrada={$lastLoad}kg".($best !== null ? ", mejor carga reciente={$best}kg" : '');
        }

        return implode("\n", $lines);
    }

    private function translateReasonCode(string $code): string
    {
        return self::REASON_CODE_PHRASES[$code] ?? 'motivo adicional sin traducción disponible — no lo inventes, resume solo lo que ya sabes';
    }
}
