<?php

namespace App\Training\Support;

use App\Models\WorkoutExercise;

/**
 * Hito C (Sustitución de un ejercicio) — resultado puro de
 * `WorkoutExerciseTargetResolver::resolve()`. Mismo patrón exacto que
 * `TrainingPreferenceIdentityResolution` (B3)/`PendingClarificationOutcome`
 * (B3.1): un estado explícito por desenlace, nunca una aproximación
 * intermedia, nunca resuelto por `first()`/`latest()` ni ningún desempate
 * arbitrario.
 *
 * - `resolved`: identidad única e inequívoca del `WorkoutExercise` objetivo.
 * - `ambiguous`: 2+ candidatos por nombre — nunca se elige por el usuario;
 *   `candidates` lleva las opciones reales para construir la clarificación.
 * - `unresolved`: ningún candidato razonable (sin ordinal válido, sin
 *   nombre que matchee, y sin frente entregado al que anclar).
 */
final readonly class WorkoutExerciseTargetResolution
{
    /**
     * @param  array<int, WorkoutExercise>  $candidates
     */
    private function __construct(
        public string $status,
        public ?WorkoutExercise $target = null,
        public array $candidates = [],
    ) {}

    public static function resolved(WorkoutExercise $target): self
    {
        return new self('resolved', $target);
    }

    /**
     * @param  array<int, WorkoutExercise>  $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self('ambiguous', candidates: $candidates);
    }

    public static function unresolved(): self
    {
        return new self('unresolved');
    }
}
