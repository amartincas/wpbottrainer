<?php

namespace App\Training\Support;

use App\Models\ExerciseLog;
use App\Models\ExerciseSet;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Decide half of the execution-report flow (Hito 6): resolves each report
 * already validated by ExecutionReportService to a real WorkoutExercise of
 * the active session — never to an exercise outside it, never to one
 * already reported — persists ExerciseLog/ExerciseSet for what was
 * genuinely reported, and closes the WorkoutSession when appropriate.
 * 100% deterministic; the LLM's output arrives already validated, this
 * class only resolves identity and writes.
 *
 * WorkoutExercise (the prescription/snapshot, Hito 4) is never written to
 * here — only ExerciseLog/ExerciseSet are created, and only via create(),
 * never update() on an existing one. See docs/DECISIONS.md.
 */
class ExecutionReportRecorder
{
    public function record(WorkoutSession $session, array $extraction): ExecutionReportOutcome
    {
        $session->load(['workoutExercises.exerciseLog']);

        $unreported = $session->workoutExercises
            ->filter(fn (WorkoutExercise $we) => $we->exerciseLog === null)
            ->values();

        $logged = [];
        $clarifications = [];

        foreach ($extraction['reports'] as $report) {
            $resolved = $this->resolveExercise($report['exercise_name'], $unreported);

            if ($resolved === null) {
                $clarifications[] = $this->clarificationMessage($report['exercise_name'], $unreported);

                continue;
            }

            if ($resolved->exerciseLog !== null) {
                // Ya reportado — nunca se sobrescribe/duplica (validaciones Hito 6).
                Log::info('TRAINING_REPORT_DUPLICATE_SKIPPED', ['workout_exercise_id' => $resolved->id]);

                continue;
            }

            if (! $report['not_performed'] && $report['sets'] === [] && $report['rpe'] === null && $report['note'] === null) {
                // Nada cuantificable ni cualitativo — falta información, se
                // pregunta en vez de asumir (Hito 6, "IA vs datos").
                $clarifications[] = "¿Cuántas series/repeticiones (o carga/duración) hiciste de {$this->nameOf($resolved)}?";
                Log::info('TRAINING_REPORT_MISSING_DATA', ['workout_exercise_id' => $resolved->id]);

                continue;
            }

            $this->persist($resolved, $report);
            $unreported = $unreported->reject(fn (WorkoutExercise $we) => $we->is($resolved))->values();
            $logged[] = $this->summaryOf($resolved, $report);
        }

        $sessionCompleted = $this->maybeCompleteSession($session);

        return new ExecutionReportOutcome($logged, $clarifications, $sessionCompleted);
    }

    /**
     * @param Collection<int, WorkoutExercise> $unreported
     */
    private function resolveExercise(?string $name, Collection $unreported): ?WorkoutExercise
    {
        if ($name !== null) {
            // Nombre explícito que no matchea ningún pendiente real (posible
            // alucinación/typo de la IA) — nunca se adivina, cae al mismo
            // clarificationMessage($name, ...) que ya existía.
            return $unreported->first(
                fn (WorkoutExercise $we) => mb_strtolower($we->exercise_snapshot['name'] ?? '') === mb_strtolower($name)
            );
        }

        // H16.2 Fase 1.1 — sin nombre explícito: se asume el ejercicio
        // ACTUALMENTE PRESENTADO — el primero de $unreported, garantizado
        // por construcción (WorkoutSession::workoutExercises() está
        // ordenado por `order`; la entrega progresiva de H16.2 Fase 1 nunca
        // muestra al usuario un ejercicio que no sea ese) — nunca una
        // suposición arbitraria entre varios. Antes de la entrega
        // progresiva esto solo era seguro si quedaba exactamente 1
        // pendiente; ahora es seguro siempre, porque el usuario nunca ha
        // visto más de uno a la vez. `first()` sobre una colección vacía ya
        // devuelve `null` de forma nativa (caso defensivo: un reporte
        // adicional sin nombre en el mismo mensaje, después de que los
        // demás pendientes ya se resolvieron en este mismo turno).
        return $unreported->first();
    }

    private function persist(WorkoutExercise $workoutExercise, array $report): void
    {
        $note = $report['note'];

        if ($report['not_performed']) {
            $note = trim('No realizado. '.($note ?? ''));
        } elseif ($report['uncertain'] && $note !== null) {
            $note = trim($note.' (cantidad aproximada, reportada con incertidumbre)');
        } elseif ($report['uncertain']) {
            $note = 'Cantidad aproximada, reportada con incertidumbre.';
        }

        $log = ExerciseLog::create([
            'workout_exercise_id' => $workoutExercise->id,
            'rpe' => $report['rpe'],
            'note' => ($note !== null && $note !== '') ? $note : null,
            'skip_reason' => $report['skip_reason'] ?? null,
            'logged_at' => now(),
        ]);

        foreach ($report['sets'] as $index => $set) {
            ExerciseSet::create([
                'exercise_log_id' => $log->id,
                'set_number' => $index + 1,
                'actual_reps' => $set['reps'],
                'actual_load' => $set['load'],
                'actual_duration_seconds' => $set['duration_seconds'],
            ]);
        }
    }

    private function maybeCompleteSession(WorkoutSession $session): bool
    {
        $session->load(['workoutExercises.exerciseLog']);

        $stillUnreported = $session->workoutExercises->contains(
            fn (WorkoutExercise $we) => $we->exerciseLog === null
        );

        // H16.2 Fase 1 (fix de la contradicción "pendientes"+"completada"):
        // una sesión NUNCA se cierra mientras exista un ejercicio realmente
        // sin ningún ExerciseLog — sin importar que el usuario haya dicho
        // "ya terminé"/equivalente. Antes de este fix, `session_finished`
        // (el antiguo parámetro $explicitlyFinished) forzaba el cierre
        // incluso con ejercicios genuinamente sin tocar; el único cierre
        // "explícito" válido ahora es el de TrainingHandler::determineSessionCloseIntent()
        // vía SessionCloseIntent::SuccessPartial, que exige que cada
        // ejercicio restante YA tenga un ExerciseLog (aunque sea "no
        // realizado") — nunca uno genuinamente Unreported. Ver
        // docs/DECISIONS.md H16.2.
        if ($stillUnreported) {
            return false;
        }

        $session->update(['status' => WorkoutSessionStatus::Completed, 'completed_at' => now()]);

        Log::info($stillUnreported ? 'TRAINING_SESSION_PARTIALLY_COMPLETED' : 'TRAINING_SESSION_COMPLETED', [
            'workout_session_id' => $session->id,
        ]);

        return true;
    }

    private function nameOf(WorkoutExercise $we): string
    {
        return $we->exercise_snapshot['name'] ?? 'ese ejercicio';
    }

    /**
     * H16.2 Fase 1.2 — lenguaje natural ("3 series de 10 repeticiones con
     * 8kg en Elevaciones de gemelos con mancuernas") en vez de notación
     * técnica ("Elevaciones de gemelos con mancuernas: 10rep@8kg"). Nunca
     * inventa un dato — solo reformula exactamente lo ya persistido.
     * Devuelve una cláusula neutra (sin verbo de apertura ni signo de
     * puntuación final) para que `TrainingHandler` la envuelva con "Registré
     * {esto}." de forma uniforme, sin importar si fue realizado, no
     * realizado, o reportado sin datos cuantificables — nunca celebra
     * automáticamente un "no realizado" (H16.2 Fase 1.2, punto 11).
     */
    private function summaryOf(WorkoutExercise $we, array $report): string
    {
        $name = $this->nameOf($we);

        if ($report['not_performed']) {
            return "que no realizaste {$name}";
        }

        if ($report['sets'] === []) {
            return "tu reporte de {$name}";
        }

        return "{$this->naturalSetsPhrase($report['sets'])} en {$name}";
    }

    /**
     * @param  array<int, array{reps: ?int, load: ?float, duration_seconds: ?int}>  $sets
     */
    private function naturalSetsPhrase(array $sets): string
    {
        $count = count($sets);
        $durations = array_values(array_filter(array_column($sets, 'duration_seconds'), fn ($d) => $d !== null));

        if ($durations !== []) {
            $uniqueDurations = array_unique($durations);

            if (count($uniqueDurations) === 1) {
                $duration = reset($uniqueDurations);

                return $count === 1 ? "1 serie de {$duration} segundos" : "{$count} series de {$duration} segundos";
            }

            return $this->naturalJoin(array_map(fn ($d) => "{$d} segundos", $durations));
        }

        $reps = array_column($sets, 'reps');
        $loads = array_column($sets, 'load');
        $uniqueReps = array_unique(array_filter($reps, fn ($r) => $r !== null));
        $uniqueLoads = array_unique(array_filter($loads, fn ($l) => $l !== null));

        if (count($uniqueReps) === 1) {
            $repsValue = reset($uniqueReps);
            $repsWord = $repsValue === 1 ? 'repetición' : 'repeticiones';
            $repsPhrase = $count === 1
                ? "1 serie de {$repsValue} {$repsWord}"
                : "{$count} series de {$repsValue} {$repsWord}";
        } else {
            $repsPhrase = $this->naturalJoin($reps).' repeticiones';
        }

        if ($uniqueLoads === []) {
            return $repsPhrase;
        }

        $loadPhrase = count($uniqueLoads) === 1
            ? ' con '.$this->formatLoad((float) reset($uniqueLoads)).'kg'
            : ' con '.$this->naturalJoin(array_map(fn ($l) => $this->formatLoad((float) $l).'kg', $loads));

        return $repsPhrase.$loadPhrase;
    }

    /**
     * @param  array<int, string|int|float>  $items
     */
    private function naturalJoin(array $items): string
    {
        $items = array_values($items);

        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }

        $last = array_pop($items);

        return implode(', ', $items).' y '.$last;
    }

    /**
     * Hardening pre-producción (hallazgo E2E de Bloque 9): formatea una
     * carga para el texto de confirmación al usuario — nunca toca el valor
     * persistido (`ExerciseSet.actual_load` guarda el float tal cual, ver
     * persist() arriba). La implementación anterior usaba
     * `rtrim((string) $load, '0')`, que le quitaba el último cero también a
     * enteros como 40 (→ "4") o 20 (→ "2"), no solo a ceros decimales de
     * sobra — number_format() a precisión fija primero, y solo entonces
     * recortar ceros/punto decimal de sobra, evita ese error: 40 → "40",
     * 40.50 → "40.5", 10 → "10".
     */
    private function formatLoad(float $load): string
    {
        return rtrim(rtrim(number_format($load, 2, '.', ''), '0'), '.');
    }

    /**
     * @param Collection<int, WorkoutExercise> $unreported
     */
    private function clarificationMessage(?string $name, Collection $unreported): string
    {
        if ($name !== null) {
            return "No tengo \"{$name}\" en tu entrenamiento de hoy — ¿a cuál ejercicio te refieres?";
        }

        $names = $unreported->map(fn (WorkoutExercise $we) => $this->nameOf($we))->implode(', ');

        return "¿A cuál ejercicio te refieres? Todavía tienes pendientes: {$names}.";
    }
}
