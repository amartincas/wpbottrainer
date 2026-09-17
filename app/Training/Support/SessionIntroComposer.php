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
 * ya congela el `goal`/`decided_focus` que realmente aplicó en el momento
 * de la prescripción — usar el perfil vivo arriesgaría leer un `goal` que
 * cambió después de generar la sesión, inconsistente con lo que el usuario
 * realmente recibió.
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
        $focusLabel = $this->focusLabel($session->prescription_context_snapshot['decided_focus'] ?? null);

        $lines = ['🔥 Tu entrenamiento de hoy', ''];

        if ($focusLabel !== null) {
            $lines[] = "Hoy trabajaremos principalmente {$focusLabel}.";
            $lines[] = '';
        }

        $lines[] = "💪 {$exerciseCount} ".($exerciseCount === 1 ? 'ejercicio' : 'ejercicios');
        $lines[] = "⏱️ Duración aproximada: {$estimatedMinutes} minutos";
        $lines[] = '';
        $lines[] = 'Vamos a comenzar.';

        return implode("\n", $lines);
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
