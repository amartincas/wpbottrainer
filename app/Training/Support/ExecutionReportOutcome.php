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
     */
    public function __construct(
        public readonly array $logged,
        public readonly array $clarifications,
        public readonly bool $sessionCompleted,
    ) {}

    public function hasAnyEffect(): bool
    {
        return $this->logged !== [] || $this->clarifications !== [];
    }
}
