<?php

namespace App\Training\Support;

/**
 * Bloque 6 — una serie realmente ejecutada, tal cual quedó en `ExerciseSet`.
 * Sin interpretación: si `load`/`durationSeconds` son `null`, es porque el
 * dato no existe — nunca se completa con la prescripción ni se infiere.
 */
final readonly class HistorySetEntry
{
    public function __construct(
        public ?int $reps,
        public ?float $load,
        public ?int $durationSeconds,
    ) {}
}
