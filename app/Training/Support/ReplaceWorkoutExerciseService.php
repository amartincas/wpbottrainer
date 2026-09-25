<?php

namespace App\Training\Support;

use App\Models\WorkoutExercise;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Hito C (Sustitución de un ejercicio, diseño formal aprobado) — orquesta la
 * sustitución de UN `WorkoutExercise` dentro de una sesión que sigue viva,
 * a petición explícita del usuario. Hermano estructural de
 * `ReplaceWorkoutSessionService` (Hito B2) — MISMO principio de
 * orquestación pura, NUNCA una superclase/interfaz compartida (decisión
 * arquitectónica ya cerrada, ver auditoría B2→C): granularidades distintas
 * (sesión completa vs. una fila), sin contrato genuinamente compartido más
 * allá del espíritu.
 *
 * Responsabilidad EXCLUSIVA de esta clase: resolver/revalidar el objetivo,
 * transacción, `lockForUpdate()`, validar estado, persistir el reemplazo y
 * marcar `superseded_by_id` del original. NUNCA selecciona — cero
 * conocimiento de `Exercise::query()`, `isEligible()`, preferencias,
 * variedad o cualquier criterio de selección: todo eso es responsabilidad
 * exclusiva de `TrainingEngine::selectReplacement()`, invocado aquí sin
 * ningún parámetro nuevo que esa firma no contemple.
 *
 * Orden EXACTO del flujo (dentro de una única `DB::transaction()`):
 * 1. Localizar el target por ID + `lockForUpdate()` — NUNCA confiar en el
 *    estado del `WorkoutExercise` recibido como parámetro, que pudo quedar
 *    obsoleto entre que el llamador lo obtuvo y esta invocación.
 * 2. Revalidar bajo lock: `superseded_by_id === null` (nadie lo sustituyó
 *    ya, ni siquiera concurrentemente), sin `ExerciseLog` (nadie lo reportó
 *    ya), sesión todavía `Scheduled`.
 * 3. `TrainingEngine::selectReplacement()` — única fuente de la
 *    prescripción del reemplazo, nunca duplicada aquí.
 * 4. Crear el `WorkoutExercise` reemplazo.
 * 5. Escribir `superseded_by_id` del original — ÚNICA escritura sobre él,
 *    nunca un paso intermedio con `null` explícito (el original YA nace y
 *    permanece `null` hasta este único `update()` final).
 *
 * NUNCA modifica: `prescription_context_snapshot` de la sesión,
 * `exercise_snapshot`/`delivered_at` del original — todos permanecen
 * exactamente como estaban, por diseño (histórico inmutable).
 */
class ReplaceWorkoutExerciseService
{
    public function __construct(private readonly TrainingEngine $engine) {}

    /**
     * @param  ?RequestedFocusGroup  $requestedFocus  Restricción de foco
     *         PUNTUAL para el reemplazo (ej. "cámbiame este por uno de
     *         pecho") — UN solo grupo, nunca un array (a diferencia de B1:
     *         aquí solo hay 1 slot que llenar). `null` si no se pidió foco.
     */
    public function replace(WorkoutExercise $target, ?RequestedFocusGroup $requestedFocus = null): ExerciseSubstitutionOutcome
    {
        return DB::transaction(function () use ($target, $requestedFocus) {
            $locked = WorkoutExercise::query()->whereKey($target->id)->lockForUpdate()->first();

            if ($locked === null) {
                return ExerciseSubstitutionOutcome::targetNotFound();
            }

            if ($locked->superseded_by_id !== null) {
                // Ya sustituido — por esta misma operación en un intento
                // anterior, o por una petición concurrente que ganó la
                // carrera del lock. Nunca se resuelve eligiendo
                // arbitrariamente cuál "gana": el segundo intento
                // simplemente ve que el objetivo ya no está disponible.
                return ExerciseSubstitutionOutcome::targetAlreadyResolved();
            }

            if ($locked->exerciseLog !== null) {
                // Ya reportado (completo, not_performed, o parcial —
                // Fase 5 del diseño: el único criterio relevante es "¿existe
                // ExerciseLog?", nunca su contenido) — ExerciseLog es
                // inmutable por diseño (create() únicamente, nunca
                // update()), así que sustituir el WorkoutExercise al que
                // pertenece introduciría una inconsistencia narrativa.
                return ExerciseSubstitutionOutcome::targetAlreadyResolved();
            }

            $session = $locked->workoutSession;

            if ($session === null || $session->status !== WorkoutSessionStatus::Scheduled) {
                return ExerciseSubstitutionOutcome::invalidTargetState();
            }

            // Única fuente de selección — TrainingEngine nunca persiste
            // (ver docblock de selectReplacement()): devuelve solo los
            // atributos listos para crear la fila.
            $attributes = $this->engine->selectReplacement($session->contact, $locked, $requestedFocus);

            $replacement = WorkoutExercise::create(array_merge(
                ['workout_session_id' => $session->id],
                $attributes,
            ));

            // ÚNICA escritura sobre el original — nunca un paso intermedio
            // con `superseded_by_id = null` (ya nace así y permanece así
            // hasta este momento exacto).
            $locked->update(['superseded_by_id' => $replacement->id]);

            return ExerciseSubstitutionOutcome::replaced($locked->fresh(), $replacement);
        });
    }
}
