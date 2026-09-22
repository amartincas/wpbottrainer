<?php

namespace App\Training\Support;

use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutExercisePhase;

/**
 * Introducción de una WorkoutSession — describe la sesión que YA fue
 * prescrita por `App\Training\Engine\TrainingEngine`, nunca decide ni
 * recalcula nada. Determinista, sin IA (MVP): mismo criterio que
 * `ExerciseMessageFormatter` — capa de presentación pura.
 *
 * `compose(WorkoutSession $session)` — un solo parámetro, deliberadamente
 * NUNCA `TrainingProfile`: `WorkoutSession.prescription_context_snapshot`
 * ya congela el `goal`/`primary_focus`/`secondary_focus`/`split_type` que
 * realmente aplicaron en el momento de la prescripción, y
 * `WorkoutExercise.exercise_snapshot` congela los músculos reales de cada
 * ejercicio ya seleccionado — usar el perfil vivo arriesgaría leer un
 * `goal` que cambió después de generar la sesión, inconsistente con lo que
 * el usuario realmente recibió.
 */
class SessionIntroComposer
{
    /**
     * Cuántos músculos reales se nombran como máximo — mismo criterio de
     * brevedad que `ExerciseMessageFormatter::MAX_TECHNIQUE_BULLETS`. Una
     * sesión con muchos ejercicios podría tocar más de 3 músculos distintos
     * — nombrarlos todos sería una introducción larga, no breve.
     */
    private const MAX_FOCUS_LABELS = 3;

    public function __construct(private readonly DurationEstimator $durationEstimator) {}

    public function compose(WorkoutSession $session): string
    {
        // Hito R1/R2/R3 — el conteo/foco que se comunica es EXCLUSIVAMENTE
        // del bloque principal (Preparation/Cooldown son apoyo, no lo que el
        // usuario entiende por "tu entrenamiento de hoy"). La duración
        // estimada, en cambio, SÍ suma las 3 fases — ver DurationEstimator,
        // sin cambios: el tiempo real que toma la sesión completa.
        $exerciseCount = $session->workoutExercises->where('phase', WorkoutExercisePhase::Main)->count();
        $estimatedMinutes = (int) round($this->durationEstimator->estimateSessionSeconds($session) / 60);
        $focusLine = $this->focusLine($session);

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
     * Hallazgo E2E real de staging + investigación de seguimiento: la
     * introducción NUNCA debe comunicar una INTENCIÓN de selección como si
     * fuera un resultado garantizado. `decided_focus` (`TrainingEngine::
     * ROTATIONS`) para `split_type=full_body` es el universo COMPLETO de los
     * 6 `Exercise.muscle_group` posibles — nunca una lista priorizada. Y
     * `primary_focus`/`secondary_focus`, aunque SÍ influyen en
     * `selectExercises()` (primaryTier/secondaryTier), no tienen ninguna
     * garantía de proporción estable (`ceil(N/2)` es un mínimo sin techo, y
     * puede fallar silenciosamente con catálogo escaso) — no alcanza para
     * afirmar honestamente "trabajaremos principalmente X". Por eso
     * `decided_focus` YA NO SE USA aquí en absoluto como fuente de foco
     * visible, en ningún caso.
     *
     * Único caso especial: `split_type=full_body` sin `primary_focus` ni
     * `secondary_focus` declarados NI `requested_focus` puntual (Hito B1.3.2)
     * — ahí no hay ningún foco, ni pretendido ni real ni solicitado, que
     * describir: "todo el cuerpo" es simplemente la verdad.
     *
     * En cualquier otro caso, la introducción describe los músculos que la
     * sesión REALMENTE contiene — ver `realMuscleFocusLabel()`.
     *
     * Hito B1.3.2 — `requested_focus` (petición puntual de ESTA sesión, ver
     * `RequestedFocusGroup`) SOLO se usa aquí para decidir si corresponde el
     * atajo de "todo el cuerpo" — nunca como fuente textual del foco en sí.
     * Con `requested_focus` presente, el flujo sigue exactamente igual hacia
     * `realMuscleFocusLabel()`, que ya describe los músculos REALES de los
     * Main ya seleccionados por `TrainingEngine` — sin importar si la
     * cobertura fue total, parcial o inexistente (`requested_focus_coverage`
     * deliberadamente NO se consulta aquí: esta corrección es puramente
     * sobre CUÁNDO activar el atajo, nunca sobre QUÉ texto producir).
     * Mismo patrón defensivo que `primary_focus`/`secondary_focus`: una
     * clave ausente (snapshot anterior a B1.3) se comporta como `[]`, sin
     * ningún cambio de comportamiento para sesiones históricas.
     */
    private function focusLine(WorkoutSession $session): ?string
    {
        $snapshot = $session->prescription_context_snapshot ?? [];
        $primaryFocus = $snapshot['primary_focus'] ?? [];
        $secondaryFocus = $snapshot['secondary_focus'] ?? [];
        $requestedFocus = $snapshot['requested_focus'] ?? [];
        $hasExplicitFocus = $primaryFocus !== [] || $secondaryFocus !== [] || $requestedFocus !== [];

        if (! $hasExplicitFocus && ($snapshot['split_type'] ?? null) === 'full_body') {
            return 'Hoy trabajaremos todo el cuerpo.';
        }

        $focusLabel = $this->realMuscleFocusLabel($session);

        return $focusLabel !== null ? "Hoy trabajaremos {$focusLabel}." : null;
    }

    /**
     * Describe lo que la sesión REALMENTE contiene, nunca una intención:
     * toma `primary_muscle` (vocabulario FINO de `MuscleFocus`, ya congelado
     * en `WorkoutExercise.exercise_snapshot` por `Exercise::toSnapshot()`)
     * de cada ejercicio ya seleccionado, en el orden de entrega real
     * (`workoutExercises()` ya ordena por `order`) — nunca `TrainingProfile`
     * vivo, nunca `decided_focus`. Reutiliza `ExerciseMessageFormatter::
     * MUSCLE_LABELS` (el mismo diccionario fino que ya usa el propio mensaje
     * de cada ejercicio, "🎯 Músculos trabajados") — única fuente de verdad,
     * nunca una taxonomía paralela.
     *
     * Regla de brevedad determinista: hasta `MAX_FOCUS_LABELS` (3) músculos
     * distintos, en el orden en que aparecen los ejercicios de la sesión —
     * mismo criterio de brevedad y "nunca inventar" que ya usaba el diseño
     * anterior. Un ejercicio sin `primary_muscle` (dato ausente en el
     * snapshot) se omite silenciosamente; una sesión sin ningún músculo
     * identificable no muestra línea de focus, nunca inventa una.
     */
    private function realMuscleFocusLabel(WorkoutSession $session): ?string
    {
        $labels = $session->workoutExercises
            ->where('phase', WorkoutExercisePhase::Main)
            ->pluck('exercise_snapshot.primary_muscle')
            ->filter()
            ->map(fn (string $muscle) => ExerciseMessageFormatter::MUSCLE_LABELS[$muscle] ?? null)
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
