<?php

namespace App\Training\Support;

use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Collection;

/**
 * P1-A — encuentra, entre TODAS las sesiones activas de TODOS los tenants,
 * el `WorkoutExercise` "actualmente pendiente" de cada una (si lo hay) que
 * ya superó el umbral de nudge configurado para su tenant. Puramente de
 * lectura — nunca envía nada ni escribe en base de datos (eso es
 * responsabilidad exclusiva de `App\Console\Commands\NudgeUnreportedExercises`).
 *
 * "Actualmente pendiente" se deriva EXACTAMENTE con el mismo criterio que ya
 * usa el resto del sistema (`ExecutionReportRecorder`/`TrainingHandler`:
 * primer `WorkoutExercise`, ordenado por `order`, sin `exerciseLog`) — nunca
 * se reimplementa como una suposición aparte. Un ejercicio con `order` mayor
 * que el pendiente actual de su sesión (todavía no entregado, por la
 * progresividad de H16.2) nunca es candidato, tenga o no `delivered_at`.
 */
class UnreportedExerciseDetector
{
    /**
     * @return Collection<int, WorkoutExercise>
     */
    public function findCandidates(): Collection
    {
        $sessionIds = WorkoutExercise::query()
            ->whereNotNull('delivered_at')
            ->whereDoesntHave('exerciseLog')
            ->whereHas('workoutSession', fn ($query) => $query->where('status', WorkoutSessionStatus::Scheduled))
            ->pluck('workout_session_id')
            ->unique();

        if ($sessionIds->isEmpty()) {
            return collect();
        }

        $sessions = WorkoutSession::query()
            ->whereIn('id', $sessionIds)
            ->with([
                'workoutExercises' => fn ($query) => $query->orderBy('order'),
                'workoutExercises.exerciseLog',
                'contact.tenant',
            ])
            ->get();

        return $sessions
            ->map(fn (WorkoutSession $session) => $session->workoutExercises
                ->first(fn (WorkoutExercise $we) => $we->exerciseLog === null))
            // Defensivo: el whereDoesntHave de arriba ya garantiza que exista
            // al menos uno, pero nunca se asume sin verificar.
            ->filter()
            ->filter(fn (WorkoutExercise $we) => $we->delivered_at !== null)
            ->filter(fn (WorkoutExercise $we) => $this->isDueForNudge($we))
            ->values();
    }

    private function isDueForNudge(WorkoutExercise $workoutExercise): bool
    {
        $tenant = $workoutExercise->workoutSession->contact->tenant;

        if (! $tenant->exercise_nudge_enabled) {
            return false;
        }

        return $workoutExercise->delivered_at->lte(now()->subMinutes($tenant->exercise_nudge_after_minutes));
    }
}
