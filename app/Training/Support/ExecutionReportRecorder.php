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

        $sessionCompleted = $this->maybeCompleteSession($session, $extraction['session_finished']);

        return new ExecutionReportOutcome($logged, $clarifications, $sessionCompleted);
    }

    /**
     * @param Collection<int, WorkoutExercise> $unreported
     */
    private function resolveExercise(?string $name, Collection $unreported): ?WorkoutExercise
    {
        if ($name !== null) {
            return $unreported->first(
                fn (WorkoutExercise $we) => mb_strtolower($we->exercise_snapshot['name'] ?? '') === mb_strtolower($name)
            );
        }

        // Sin nombre explícito: solo se asume si hay exactamente un
        // ejercicio pendiente — nunca se adivina entre varios.
        return $unreported->count() === 1 ? $unreported->first() : null;
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

    private function maybeCompleteSession(WorkoutSession $session, bool $explicitlyFinished): bool
    {
        $session->load(['workoutExercises.exerciseLog']);

        $stillUnreported = $session->workoutExercises->contains(
            fn (WorkoutExercise $we) => $we->exerciseLog === null
        );

        if (! $explicitlyFinished && $stillUnreported) {
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

    private function summaryOf(WorkoutExercise $we, array $report): string
    {
        $name = $this->nameOf($we);

        if ($report['not_performed']) {
            return "{$name}: no realizado";
        }

        if ($report['sets'] === []) {
            return $name;
        }

        $setsText = collect($report['sets'])->map(function (array $set) {
            if ($set['duration_seconds'] !== null) {
                return "{$set['duration_seconds']}s";
            }

            $load = $set['load'] !== null ? '@'.rtrim(rtrim((string) $set['load'], '0'), '.').'kg' : '';

            return trim("{$set['reps']}rep{$load}");
        })->implode(', ');

        return "{$name}: {$setsText}";
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
