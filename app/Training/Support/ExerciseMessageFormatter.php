<?php

namespace App\Training\Support;

use App\Models\WorkoutExercise;

/**
 * Hito 9.2 — construye el texto que el usuario recibe por cada ejercicio
 * (nombre + prescripción + técnica + respiración + precauciones). Lee
 * EXCLUSIVAMENTE de `WorkoutExercise.exercise_snapshot` — nunca del
 * `Exercise` en vivo ni de ningún proveedor — para que una mejora futura a
 * la técnica de un ejercicio no reescriba retroactivamente lo que un
 * usuario ya recibió (misma garantía de inmutabilidad histórica que el
 * resto del snapshot, ver docs/DECISIONS.md).
 *
 * Separación de responsabilidades (Hito 9.2, aprobada explícitamente):
 * TrainingEngine decide QUÉ ejercicio y CUÁNTO (series/reps/descanso);
 * Exercise/catálogo contiene la información técnica; este formateador
 * CONSTRUYE el texto; MediaResolver resuelve el video; WhatsAppService lo
 * entrega. Ninguna decisión de negocio vive aquí — es una capa de
 * presentación pura, sin scoring, sin selección, sin acceso a IA.
 *
 * Nunca hardcodea contenido por ejercicio — todo el texto se arma a
 * partir de lo que el snapshot trae; un ejercicio sin `important_points`/
 * `common_mistakes`/`breathing_cue` simplemente omite esas secciones,
 * nunca deja un encabezado vacío.
 */
class ExerciseMessageFormatter
{
    /**
     * Brevedad accionable (Hito 9.2, aprobado): técnica e instrucciones se
     * combinan en una sola lista de viñetas para el usuario (no le importa
     * la distinción interna entre "pasos" y "puntos clave"), acotada para
     * que el mensaje siga siendo corto.
     */
    private const MAX_TECHNIQUE_BULLETS = 4;

    private const MAX_MISTAKE_BULLETS = 2;

    public function format(WorkoutExercise $workoutExercise, int $order): string
    {
        $snapshot = $workoutExercise->exercise_snapshot;
        $lines = [];

        $lines[] = "{$order}. *{$snapshot['name']}* — {$this->formatPrescription($workoutExercise)}";
        $lines[] = '';

        $technique = $this->techniqueBullets($snapshot);
        if ($technique !== []) {
            $lines[] = '📋 Técnica:';
            foreach ($technique as $bullet) {
                $lines[] = "- {$bullet}";
            }
            $lines[] = '';
        }

        if (! empty($snapshot['breathing_cue'])) {
            $lines[] = "🫁 Respiración: {$snapshot['breathing_cue']}";
            $lines[] = '';
        }

        $mistakes = array_slice($snapshot['common_mistakes'] ?? [], 0, self::MAX_MISTAKE_BULLETS);
        if ($mistakes !== []) {
            $lines[] = '⚠️ Evita:';
            foreach ($mistakes as $mistake) {
                $lines[] = "- {$mistake}";
            }
            $lines[] = '';
        }

        $lines[] = '🎥 Video a continuación';

        return implode("\n", $lines);
    }

    private function formatPrescription(WorkoutExercise $workoutExercise): string
    {
        if ($workoutExercise->prescribed_duration_seconds !== null) {
            return "{$workoutExercise->prescribed_sets} series x {$workoutExercise->prescribed_duration_seconds} segundos";
        }

        $loadText = $workoutExercise->prescribed_load !== null
            ? ' @ '.rtrim(rtrim((string) $workoutExercise->prescribed_load, '0'), '.').'kg'
            : '';

        return "{$workoutExercise->prescribed_sets} series x {$workoutExercise->prescribed_reps} repeticiones{$loadText}";
    }

    /**
     * @return string[]
     */
    private function techniqueBullets(array $snapshot): array
    {
        $combined = array_merge(
            $snapshot['instructions'] ?? [],
            $snapshot['important_points'] ?? [],
        );

        return array_slice($combined, 0, self::MAX_TECHNIQUE_BULLETS);
    }
}
