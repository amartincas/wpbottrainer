<?php

namespace App\Training\Support;

/**
 * Hito B1 (Requested Focus) — unidad de intención de un `requested_focus`.
 * Representa UN término solicitado por el usuario (ej. "pecho", "piernas"),
 * ya canonicalizado por `RequestedFocusTermMapper` — nunca texto libre.
 *
 * Deliberadamente NO es `array<MuscleFocus>` plano: un array plano colapsa
 * "piernas" (un solo grupo compuesto por 4 `MuscleFocus`) y "cuádriceps,
 * isquiotibiales, glúteos, pantorrillas" (4 grupos independientes) en la
 * misma representación, perdiendo la distinción que `TrainingEngine`
 * necesita para reservar slots por GRUPO (ver
 * `TrainingEngine::selectExercisesForRequestedFocus()`), no por músculo
 * individual.
 *
 * - `key`: identidad canónica y cerrada del grupo (ej. "chest", "legs"),
 *   nunca texto libre — proviene siempre de `RequestedFocusTermMapper`.
 * - `muscles`: valores de `MuscleFocus->value` que representan el grupo.
 *   Relación OR interna — cualquiera de ellos satisface al grupo (ver
 *   `TrainingEngine::exerciseMuscles()`/relevancia de grupo).
 *
 * Invariantes validados en el constructor (nunca en el llamador): un grupo
 * sin músculos, o con músculos duplicados, es un error de programación del
 * mapeador que lo construyó — nunca un estado válido que TrainingEngine deba
 * tolerar.
 */
final class RequestedFocusGroup
{
    /**
     * @param  array<int, string>  $muscles  MuscleFocus->value, no vacío, sin duplicados.
     */
    public function __construct(
        public readonly string $key,
        public readonly array $muscles,
    ) {
        if ($muscles === []) {
            throw new \InvalidArgumentException("RequestedFocusGroup '{$key}' must have at least one muscle.");
        }

        if (count($muscles) !== count(array_unique($muscles))) {
            throw new \InvalidArgumentException("RequestedFocusGroup '{$key}' must not contain duplicate muscles.");
        }
    }

    /**
     * Hito C (fix contexto de foco en sustitución) — reconstruye
     * `RequestedFocusGroup[]` desde la forma serializada `{key, muscles}`
     * que `TrainingEngine::decideNextSession()` ya escribe en
     * `WorkoutSession.prescription_context_snapshot.requested_focus`.
     * Única fuente de verdad de este shape de deserialización — evita que
     * `ReplaceWorkoutSessionService` (B2) y `ReplaceWorkoutExerciseService`
     * (C) mantengan cada una su propia copia de este parsing, con riesgo de
     * que diverjan si el shape cambia. Mismo criterio EXACTO que ya usa
     * `ReplaceWorkoutSessionService::inheritedRequestedFocus()` (B2, no
     * modificado por este cambio — se deja intacto para no tocar código de
     * B2 fuera de lo estrictamente necesario).
     *
     * @param  array<int, array{key: string, muscles: array<int,string>}>  $serialized
     * @return array<int, self>
     */
    public static function manyFromArray(array $serialized): array
    {
        return array_map(fn (array $group) => new self($group['key'], $group['muscles']), $serialized);
    }
}
