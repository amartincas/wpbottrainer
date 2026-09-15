<?php

namespace App\Training\Support;

/**
 * Result of App\Training\Support\ExecutionReportRecorder::record() — what
 * actually got persisted, what still needs clarification from the user, and
 * whether the WorkoutSession was closed as a result of this turn.
 */
final class ExecutionReportOutcome
{
    /**
     * @param string[] $logged human-readable summaries of what was persisted
     * @param string[] $clarifications questions to send back to the user
     * @param int[] $partialExerciseIds H16.2 Fase 1.3 — WorkoutExercise IDs
     *        que se persistieron con MENOS series que las prescritas este
     *        turno (ver ExecutionReportRecorder::isPartialReport()). El
     *        consumidor (TrainingHandler) debe tratarlos como todavía
     *        pendientes para efectos de avance/cierre de ESTE turno, sin que
     *        eso cambie el significado de "Unreported" en ningún otro lugar.
     */
    public function __construct(
        public readonly array $logged,
        public readonly array $clarifications,
        public readonly bool $sessionCompleted,
        public readonly array $partialExerciseIds = [],
    ) {}

    public function hasAnyEffect(): bool
    {
        return $this->logged !== [] || $this->clarifications !== [];
    }
}
