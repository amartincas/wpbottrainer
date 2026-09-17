<?php

namespace App\Training\Support;

use App\Models\WorkoutSession;

/**
 * Introducción de una WorkoutSession — describe la sesión que YA fue
 * prescrita por `App\Training\Engine\TrainingEngine`, nunca decide ni
 * recalcula nada. Determinista, sin IA (MVP): mismo criterio que
 * `ExerciseMessageFormatter` — capa de presentación pura.
 *
 * `compose(WorkoutSession $session)` — un solo parámetro, deliberadamente
 * NUNCA `TrainingProfile`: `WorkoutSession.prescription_context_snapshot`
 * ya congela el `goal`/`decided_focus`/`primary_focus`/`secondary_focus`/
 * `split_type` que realmente aplicaron en el momento de la prescripción —
 * usar el perfil vivo arriesgaría leer un `goal` que cambió después de
 * generar la sesión, inconsistente con lo que el usuario realmente recibió.
 */
class SessionIntroComposer
{
    /**
     * Cuántos grupos musculares del focus se nombran como máximo — mismo
     * criterio de brevedad que `ExerciseMessageFormatter::MAX_TECHNIQUE_BULLETS`.
     * `decided_focus` puede traer hasta 6 grupos (rotación full_body
     * completa) — nombrarlos todos sería una introducción larga, no breve.
     */
    private const MAX_FOCUS_LABELS = 3;

    public function __construct(private readonly DurationEstimator $durationEstimator) {}

    public function compose(WorkoutSession $session): string
    {
        $exerciseCount = $session->workoutExercises->count();
        $estimatedMinutes = (int) round($this->durationEstimator->estimateSessionSeconds($session) / 60);
        $focusLine = $this->focusLine($session->prescription_context_snapshot ?? []);

        $lines = ['🔥 Tu entrenamiento de hoy', ''];

        if ($focusLine !== null) {
            $lines[] = $focusLine;
            $lines[] = '';
        }

        $lines[] = "💪 {$exerciseCount} ".($exerciseCount === 1 ? 'ejercicio' : 'ejercicios');
        $lines[] = "⏱️ Duración aproximada: {$estimatedMinutes} minutos";
        $lines[] = '';
        $lines[] = 'Vamos a comenzar.';

        return implode("\n", $lines);
    }

    /**
     * Hallazgo E2E real de staging (contacto full_body sin foco explícito):
     * `decided_focus` para `split_type=full_body` es el universo COMPLETO de
     * los 6 `Exercise.muscle_group` posibles (`TrainingEngine::ROTATIONS`:
     * "full_body no tiene nada que rotar" — un único elemento compuesto por
     * los 6 grupos), NUNCA una lista priorizada — a diferencia de
     * upper_lower/push_pull_legs, donde cada fase de la rotación SÍ es un
     * subconjunto genuino que además acota la selección real
     * (`TrainingEngine::selectExercises()`, `$generalTier`). Truncar ese
     * universo completo a los primeros 3 (orden alfabético fijo de la
     * constante, sin ningún significado de prioridad) y anunciarlos como
     * "trabajaremos principalmente X, Y y Z" es, para full_body, una
     * afirmación falsa — la sesión real puede (y normalmente va a)
     * seleccionar ejercicios de cualquiera de los 6 grupos, sin relación con
     * esos 3 primeros.
     *
     * Distinción correcta (nunca posicional dentro de `decided_focus`):
     * ¿el perfil declaró un foco real (`primary_focus`/`secondary_focus`,
     * congelados en el snapshot)? Si NO, y el split es full_body, no hay
     * ningún foco genuino que anunciar — se comunica "todo el cuerpo". En
     * cualquier otro caso (foco explícito declarado, o un split con fases
     * reales como upper_lower/push_pull_legs) se conserva el comportamiento
     * previo, mostrando `decided_focus` traducido — sin cambios ahí.
     *
     * El problema de bodyweight/TrackingType/carga (hallado en la misma
     * prueba E2E) es un hito aparte, explícitamente fuera de este parche.
     */
    private function focusLine(array $snapshot): ?string
    {
        $primaryFocus = $snapshot['primary_focus'] ?? [];
        $secondaryFocus = $snapshot['secondary_focus'] ?? [];
        $hasExplicitFocus = $primaryFocus !== [] || $secondaryFocus !== [];

        if (! $hasExplicitFocus && ($snapshot['split_type'] ?? null) === 'full_body') {
            return 'Hoy trabajaremos todo el cuerpo.';
        }

        $focusLabel = $this->focusLabel($snapshot['decided_focus'] ?? null);

        return $focusLabel !== null ? "Hoy trabajaremos principalmente {$focusLabel}." : null;
    }

    /**
     * `decided_focus` es una lista de `Exercise.muscle_group` separada por
     * comas — el vocabulario GRUESO de 6 valores que produce
     * `TrainingEngine::ROTATIONS` (arms, back, chest, core, legs,
     * shoulders), no el vocabulario fino de `MuscleFocus`. Reutiliza
     * `ExerciseMessageFormatter::MUSCLE_GROUP_LABELS` (única fuente de
     * verdad de ESTE diccionario, nunca duplicado aquí) — cubre los 6
     * valores exactos que `ROTATIONS` puede producir, así que ningún focus
     * real desaparece silenciosamente. Un token fuera de ese vocabulario
     * (no debería ocurrir nunca dado el código actual) se omitiría, nunca
     * inventando una etiqueta.
     */
    private function focusLabel(?string $decidedFocus): ?string
    {
        if ($decidedFocus === null) {
            return null;
        }

        $labels = collect(explode(',', $decidedFocus))
            ->map(fn (string $muscleGroup) => ExerciseMessageFormatter::MUSCLE_GROUP_LABELS[$muscleGroup] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->take(self::MAX_FOCUS_LABELS);

        if ($labels->isEmpty()) {
            return null;
        }

        return $this->naturalJoin($labels->all());
    }

    /**
     * @param  string[]  $items
     */
    private function naturalJoin(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' y '.$last;
    }
}
