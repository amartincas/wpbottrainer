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

        $weightGuidance = $this->weightGuidance($workoutExercise);
        if ($weightGuidance !== null) {
            $lines[] = $weightGuidance;
            $lines[] = '';
        }

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

    /**
     * Hito 15.1 (Ronda 2, Cambio 3) — SOLO cuando `TrainingEngine` todavía
     * no calculó ninguna carga (decisión "insufficient_data" —
     * ver `numericPrescriptionFor()` — nunca inventa un peso) Y el
     * ejercicio realmente requiere equipo de carga externa — nunca para
     * un ejercicio de peso corporal, donde "elige un peso" no tendría
     * sentido.
     *
     * `equipment_needed` NO vive en `exercise_snapshot` (histórico,
     * congelado — ver `Exercise::toSnapshot()`) — se lee de la relación
     * `Exercise` EN VIVO, únicamente para decidir SI se muestra esta
     * orientación, nunca para reconstruir ni alterar ningún dato
     * histórico del snapshot (mismo criterio que ya usa `TrainingHandler`
     * para resolver el video vía `MediaResolver`). Si la relación no
     * resuelve (ejercicio borrado) o `equipment_needed` no puede
     * determinarse, se omite la orientación — nunca se asume que el
     * ejercicio requiere carga.
     *
     * Lenguaje natural, sin exponer "RPE" (jerga interna) al usuario —
     * mismo criterio que el resto de mensajes del bot.
     */
    private function weightGuidance(WorkoutExercise $workoutExercise): ?string
    {
        if ($workoutExercise->prescribed_duration_seconds !== null || $workoutExercise->prescribed_load !== null) {
            return null;
        }

        $equipmentNeeded = $workoutExercise->exercise?->equipment_needed;

        if (! is_array($equipmentNeeded) || $equipmentNeeded === []) {
            return null;
        }

        return 'Elige un peso con el que las últimas 2-3 repeticiones te cuesten de verdad, sin perder la técnica. '
            .'Cuéntame qué peso usaste cuando reportes, así ajusto la próxima vez.';
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
