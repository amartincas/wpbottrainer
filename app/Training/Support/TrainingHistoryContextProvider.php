<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Models\ExerciseSet;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\HistoryExerciseOutcome;
use App\Training\Enums\WorkoutSessionStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Bloque 6 (ver docs/DECISIONS.md D049) — capa de SOLO LECTURA, determinista
 * y sin IA que construye `TrainingHistoryContext` a partir del historial
 * real. No escribe nada, no decide nada, no reemplaza ninguna fuente de
 * verdad existente.
 *
 * Arquitectura: WorkoutSession/WorkoutExercise/ExerciseLog/ExerciseSet +
 * snapshots históricos + TrainingProfile + SafetyRestrictionResolver
 * (reutilizado, nunca recalculado) → TrainingHistoryContext.
 *
 * `TrainingEngine` NO consume esto todavía — es exclusivamente la capa
 * preparatoria para un futuro `ProgressionEvaluator`/`CoachService`, sin
 * ninguna integración nueva en este bloque.
 */
class TrainingHistoryContextProvider
{
    /**
     * Ventana histórica (AND, no OR — ver docs/DECISIONS.md D049): como
     * máximo estas sesiones, Y dentro de estas semanas. 6 sesiones garantiza
     * ver al menos 2 ciclos completos de la rotación más granular de
     * TrainingEngine (push_pull_legs, 3 franjas); 4 semanas evita que una
     * sexta sesión muy antigua (baja adherencia) se presente como reciente.
     */
    private const MAX_SESSIONS = 6;

    private const WINDOW_WEEKS = 4;

    public function __construct(private readonly SafetyRestrictionResolver $safetyResolver) {}

    public function build(Contact $contact): TrainingHistoryContext
    {
        $profile = $contact->trainingProfile;

        $sessions = $contact->workoutSessions()
            ->whereIn('status', [WorkoutSessionStatus::Completed, WorkoutSessionStatus::Skipped])
            ->where('scheduled_at', '>=', now()->subWeeks(self::WINDOW_WEEKS))
            ->with('workoutExercises.exerciseLog.exerciseSets')
            ->orderByDesc('scheduled_at')
            ->limit(self::MAX_SESSIONS)
            ->get();

        $sessionEntries = $sessions
            ->map(fn (WorkoutSession $session) => $this->buildSessionEntry($session))
            ->values();

        return new TrainingHistoryContext(
            windowSessionsCount: $sessionEntries->count(),
            windowWeeks: self::WINDOW_WEEKS,
            sessions: $sessionEntries->all(),
            aggregates: $this->buildAggregates($sessionEntries),
            currentProfileSnapshot: $this->buildProfileSnapshot($profile),
            activeSafetyBodyRegions: $profile !== null ? $this->safetyResolver->activeSafetyBodyRegions($profile) : [],
        );
    }

    private function buildSessionEntry(WorkoutSession $session): HistorySessionEntry
    {
        $exerciseEntries = $session->workoutExercises
            ->map(fn (WorkoutExercise $we) => $this->buildExerciseEntry($we))
            ->values()
            ->all();

        $snapshot = $session->prescription_context_snapshot;

        return new HistorySessionEntry(
            workoutSessionId: $session->id,
            status: $session->status,
            effectiveDate: $session->completed_at
                ?? $this->earliestLoggedAt($exerciseEntries)
                ?? $session->scheduled_at,
            scheduledAt: $session->scheduled_at,
            completedAt: $session->completed_at,
            decidedFocus: $snapshot['decided_focus'] ?? null,
            goal: $snapshot['goal'] ?? null,
            exercises: $exerciseEntries,
        );
    }

    /**
     * @param  array<int, HistoryExerciseEntry>  $exerciseEntries
     */
    private function earliestLoggedAt(array $exerciseEntries): ?CarbonInterface
    {
        $dates = array_values(array_filter(array_map(
            fn (HistoryExerciseEntry $e) => $e->loggedAt,
            $exerciseEntries,
        )));

        if ($dates === []) {
            return null;
        }

        return collect($dates)->min();
    }

    private function buildExerciseEntry(WorkoutExercise $workoutExercise): HistoryExerciseEntry
    {
        $log = $workoutExercise->exerciseLog;

        $outcome = match (true) {
            $log === null => HistoryExerciseOutcome::Unreported,
            $log->exerciseSets->isEmpty() => HistoryExerciseOutcome::Skipped,
            default => HistoryExerciseOutcome::Performed,
        };

        $sets = $log?->exerciseSets
            ->map(fn (ExerciseSet $set) => new HistorySetEntry(
                reps: $set->actual_reps,
                load: $set->actual_load !== null ? (float) $set->actual_load : null,
                durationSeconds: $set->actual_duration_seconds,
            ))
            ->all() ?? [];

        return new HistoryExerciseEntry(
            exerciseId: $workoutExercise->exercise_id,
            name: $workoutExercise->exercise_snapshot['name'] ?? '',
            outcome: $outcome,
            sets: $sets,
            rpe: $log?->rpe,
            note: $log?->note,
            skipReason: $log?->skip_reason,
            loggedAt: $log?->logged_at,
        );
    }

    /**
     * @param  Collection<int, HistorySessionEntry>  $sessionEntries
     */
    private function buildAggregates(Collection $sessionEntries): HistoryAggregates
    {
        $sessionsCompleted = $sessionEntries
            ->filter(fn (HistorySessionEntry $s) => $s->status === WorkoutSessionStatus::Completed)
            ->count();

        // Agrupa las ejecuciones REALIZADAS por exercise_id, preservando el
        // orden "más reciente primero" — $sessionEntries ya viene ordenado
        // así (scheduled_at DESC), así que el primer elemento de cada grupo
        // es, por construcción, la ejecución más reciente de ese ejercicio.
        // exercise_id = null (Exercise borrado) se excluye de TODOS los
        // agregados agrupados — no hay identidad estable para agrupar.
        $performedByExercise = [];

        foreach ($sessionEntries as $sessionEntry) {
            foreach ($sessionEntry->exercises as $exerciseEntry) {
                if ($exerciseEntry->exerciseId === null) {
                    continue;
                }

                if ($exerciseEntry->outcome !== HistoryExerciseOutcome::Performed) {
                    continue;
                }

                $performedByExercise[$exerciseEntry->exerciseId][] = $exerciseEntry;
            }
        }

        $lastLoad = [];
        $bestLoad = [];
        $recentRepRange = [];
        $lastPerformedAt = [];
        $repeatedCount = [];

        foreach ($performedByExercise as $exerciseId => $entries) {
            $repeatedCount[$exerciseId] = count($entries);

            foreach ($entries as $entry) {
                if ($entry->loggedAt !== null) {
                    $lastPerformedAt[$exerciseId] = $entry->loggedAt;

                    break;
                }
            }

            // lastLoadByExerciseId: SOLO la ejecución más reciente
            // ($entries[0]) — nunca se busca hacia atrás.
            $mostRecentLoad = $this->loadOfExecution($entries[0]);

            if ($mostRecentLoad !== null) {
                $lastLoad[$exerciseId] = $mostRecentLoad;
            }

            // bestRecentLoadByExerciseId: máximo entre TODAS las ejecuciones
            // de la ventana (nunca fuera de ella).
            $loadsInWindow = array_values(array_filter(
                array_map(fn (HistoryExerciseEntry $e) => $this->loadOfExecution($e), $entries),
                fn (?float $load) => $load !== null,
            ));

            if ($loadsInWindow !== []) {
                $bestLoad[$exerciseId] = max($loadsInWindow);
            }

            $reps = [];

            foreach ($entries as $entry) {
                foreach ($entry->sets as $set) {
                    if ($set->reps !== null) {
                        $reps[] = $set->reps;
                    }
                }
            }

            if ($reps !== []) {
                $recentRepRange[$exerciseId] = ['min' => min($reps), 'max' => max($reps)];
            }
        }

        $lastCompletedDate = $sessionEntries
            ->filter(fn (HistorySessionEntry $s) => $s->status === WorkoutSessionStatus::Completed)
            ->map(fn (HistorySessionEntry $s) => $s->effectiveDate)
            ->sortDesc()
            ->first();

        return new HistoryAggregates(
            sessionsCompletedInWindow: $sessionsCompleted,
            lastLoadByExerciseId: $lastLoad,
            bestRecentLoadByExerciseId: $bestLoad,
            recentRepRange: $recentRepRange,
            lastPerformedAtByExerciseId: $lastPerformedAt,
            exercisesRepeatedInWindow: $repeatedCount,
            daysSinceLastCompletedSession: $lastCompletedDate !== null
                ? (int) abs($lastCompletedDate->diffInDays(now()))
                : null,
        );
    }

    /**
     * Carga de UNA ejecución: el máximo `actual_load` entre sus sets con
     * dato (mismo criterio que el "top set" que
     * `TrainingEngine::progressionFor()` ya usa hoy). `null` si ningún set
     * tiene carga — nunca `0` por defecto. Un ejercicio por duración nunca
     * tiene sets con `load` no nulo, así que naturalmente nunca aporta aquí.
     */
    private function loadOfExecution(HistoryExerciseEntry $entry): ?float
    {
        $loads = array_values(array_filter(
            array_map(fn (HistorySetEntry $s) => $s->load, $entry->sets),
            fn (?float $load) => $load !== null,
        ));

        return $loads === [] ? null : max($loads);
    }

    /**
     * Mismo subconjunto exacto de campos que `TrainingEngine` consume de
     * `TrainingProfile` — verificado por inspección directa (grep de
     * `$profile->` en TrainingEngine.php) antes de implementar. `restrictions`
     * no aparece porque TrainingEngine tampoco lo lee directamente — pasa
     * por SafetyRestrictionResolver, ya expuesto aparte como
     * `activeSafetyBodyRegions`.
     */
    private function buildProfileSnapshot(?TrainingProfile $profile): array
    {
        if ($profile === null) {
            return [];
        }

        return [
            'goal' => $profile->goal?->value,
            'experience_level' => $profile->experience_level?->value,
            'primary_focus' => $profile->primary_focus,
            'secondary_focus' => $profile->secondary_focus,
            'split_type' => $profile->split_type?->value,
            'next_focus' => $profile->next_focus,
            'training_location' => $profile->training_location?->value,
            'equipment_fully_equipped' => $profile->equipment_fully_equipped,
            'available_equipment' => $profile->available_equipment,
        ];
    }
}
