<?php

namespace App\Training\Support;

use App\Models\WorkoutExercise;

/**
 * Hito C (Sustitución de un ejercicio, diseño formal aprobado) — resultado
 * puro de `ReplaceWorkoutExerciseService::replace()`. Mismo patrón exacto
 * que `PendingClarificationOutcome`/`TrainingPreferenceIdentityResolution`:
 * un estado explícito por desenlace de NEGOCIO, nunca una excepción — las
 * excepciones quedan reservadas para inconsistencias técnicas reales
 * (`TrainingCatalogInsufficientException`, reutilizada tal cual de
 * `TrainingEngine::decideNextSession()` cuando no existe ningún candidato
 * elegible).
 *
 * `ambiguous_target` se declara aquí por completitud del contrato, pero
 * esta fase (infraestructura de dominio) nunca lo produce —
 * `ReplaceWorkoutExerciseService::replace()` recibe un `WorkoutExercise` ya
 * identificado, nunca una frase ambigua en lenguaje natural; la resolución
 * de "este"/nombre/ordinal (y por tanto la posibilidad real de ambigüedad)
 * es responsabilidad de la fase conversacional siguiente, todavía no
 * implementada.
 */
final readonly class ExerciseSubstitutionOutcome
{
    private function __construct(
        public string $status,
        public ?WorkoutExercise $original = null,
        public ?WorkoutExercise $replacement = null,
    ) {}

    public static function replaced(WorkoutExercise $original, WorkoutExercise $replacement): self
    {
        return new self('replaced', $original, $replacement);
    }

    public static function targetNotFound(): self
    {
        return new self('target_not_found');
    }

    /**
     * Reservado para la fase conversacional (resolución de lenguaje
     * natural) — no producido por esta fase de infraestructura.
     */
    public static function ambiguousTarget(): self
    {
        return new self('ambiguous_target');
    }

    public static function targetAlreadyResolved(): self
    {
        return new self('target_already_resolved');
    }

    public static function invalidTargetState(): self
    {
        return new self('invalid_target_state');
    }
}
