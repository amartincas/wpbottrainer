<?php

namespace App\Training\Support;

use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;

/**
 * Estima cuánto debería durar, aproximadamente, un ejercicio o una sesión —
 * NUNCA decide nada: no selecciona ejercicios, no decide elegibilidad, no
 * decide cantidad, no consulta IA, no consulta proveedores, no modifica
 * `WorkoutSession`/`WorkoutExercise`. `App\Training\Engine\TrainingEngine`
 * sigue siendo la única autoridad de prescripción — este servicio solo hace
 * aritmética determinista sobre valores de prescripción que otro ya decidió
 * (ya sean hipotéticos, vía `GOAL_DEFAULTS`, o reales, ya persistidos en un
 * `WorkoutExercise`).
 *
 * `estimateSessionSeconds()` lee EXCLUSIVAMENTE las prescripciones reales ya
 * persistidas (`prescribed_sets`/`rest_seconds`/`prescribed_duration_seconds`)
 * — nunca reconstruye nada desde `TrainingProfile` ni vuelve a ejecutar
 * `TrainingEngine`.
 */
class DurationEstimator
{
    /**
     * HEURÍSTICA DE PRODUCTO, no un dato científico ni fisiológico —
     * revisable en cualquier momento sin migración (mismo criterio que
     * `TrainingEngine::GOAL_DEFAULTS`). Representa cuánto tarda en
     * ejecutarse UNA serie de repeticiones, como cifra única independiente
     * del número exacto de repeticiones — deliberadamente no se modela por
     * tempo/velocidad individual de repetición (fuera de alcance del MVP).
     * Para ejercicios `time_based`, se usa en su lugar el dato real ya
     * prescrito (`prescribed_duration_seconds`), sin ninguna heurística.
     */
    private const AVERAGE_SET_EXECUTION_SECONDS = 120;

    /**
     * @param  int  $sets  Número de series.
     * @param  int  $restSeconds  Descanso entre series.
     * @param  ?int  $durationSeconds  Duración real prescrita por serie,
     *         SOLO para ejercicios `time_based` — null para ejercicios de
     *         repeticiones, donde se usa `AVERAGE_SET_EXECUTION_SECONDS`.
     */
    public function estimateExerciseSeconds(int $sets, int $restSeconds, ?int $durationSeconds): int
    {
        $perSetSeconds = $durationSeconds ?? self::AVERAGE_SET_EXECUTION_SECONDS;

        return $sets * ($perSetSeconds + $restSeconds);
    }

    /**
     * Suma la estimación de cada `WorkoutExercise` YA PERSISTIDO de la
     * sesión — sobre datos reales, nunca hipotéticos.
     */
    public function estimateSessionSeconds(WorkoutSession $session): int
    {
        return $session->workoutExercises->sum(
            fn (WorkoutExercise $workoutExercise) => $this->estimateExerciseSeconds(
                $workoutExercise->prescribed_sets,
                $workoutExercise->rest_seconds,
                $workoutExercise->prescribed_duration_seconds,
            )
        );
    }
}
