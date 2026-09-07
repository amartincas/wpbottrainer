<?php

namespace App\Training\Context;

use App\Core\Memory\ContextFragment;
use App\Core\Memory\ContextProviderInterface;
use App\Core\Messaging\ExecutionContext;
use App\Models\Contact;
use App\Models\ExerciseSet;
use App\Models\WhatsAppMessage;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\HistoryExerciseOutcome;
use App\Training\Enums\TrackingType;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\HistorySetEntry;
use App\Training\Support\ProgressionEvaluation;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\TrainingHistoryContext;
use App\Training\Support\TrainingHistoryContextProvider;

/**
 * Bloque 9 (D052) — compone `CoachContext` para UNA interacción. Reutiliza
 * `TrainingHistoryContextProvider::build()` y `ProgressionEvaluator::evaluate()`
 * literalmente (mismo contexto, sin recalcular ni duplicar su lógica de
 * ventana/decisión) — ninguno de los dos se modifica en este bloque.
 *
 * Recorte deliberado, documentado: `TrainingHistoryContextProvider` (D049)
 * EXCLUYE a propósito la sesión `Scheduled` activa de su ventana — por eso
 * este proveedor construye `CoachSessionSnapshot`/`CoachExerciseSnapshot`
 * (tipos propios, distintos de `HistorySessionEntry`/`HistoryExerciseEntry`
 * de D049) leyendo directamente `WorkoutSession`/`WorkoutExercise` para la
 * sesión ACTUAL — la derivación de `outcome`/sets (Unreported/Skipped/
 * Performed) replica, en un puñado de líneas, exactamente el mismo criterio
 * ya usado por D049 para ejecuciones cerradas, porque ese método es privado
 * y `TrainingHistoryContextProvider` no se modifica en este bloque (mismo
 * criterio, nunca uno nuevo).
 */
class CoachContextProvider implements ContextProviderInterface
{
    private const RECENT_MESSAGES_LIMIT = 10;

    public function __construct(
        private readonly TrainingHistoryContextProvider $historyProvider,
        private readonly ProgressionEvaluator $progressionEvaluator,
    ) {}

    public function provide(ExecutionContext $context): ContextFragment
    {
        $contact = Contact::where('tenant_id', $context->tenant->id)
            ->where('customer_phone', $context->message->from)
            ->first();

        if ($contact === null) {
            return new ContextFragment(
                label: 'coach_context',
                data: null,
                source: 'db',
                confidence: 'unknown',
            );
        }

        $historyContext = $this->historyProvider->build($contact);
        $currentSession = $this->resolveCurrentSession($contact);
        $progressionEvaluations = $this->evaluateProgressionsFor($currentSession, $historyContext);
        $recentMessages = $this->recentMessagesFor($context);

        $coachContext = new CoachContext(
            profileSnapshot: $historyContext->currentProfileSnapshot,
            currentSession: $currentSession,
            historyContext: $historyContext,
            progressionEvaluations: $progressionEvaluations,
            recentMessages: $recentMessages,
        );

        return new ContextFragment(
            label: 'coach_context',
            data: $coachContext,
            source: 'db',
            confidence: 'confirmed',
        );
    }

    /**
     * La sesión `Scheduled` pendiente si existe; si no, la `Completed`/
     * `Skipped` más reciente; `null` si el contacto nunca entrenó.
     */
    private function resolveCurrentSession(Contact $contact): ?CoachSessionSnapshot
    {
        $session = $contact->workoutSessions()
            ->where('status', WorkoutSessionStatus::Scheduled)
            ->with(['workoutExercises.exercise', 'workoutExercises.exerciseLog.exerciseSets'])
            ->orderByDesc('scheduled_at')
            ->first();

        $session ??= $contact->workoutSessions()
            ->whereIn('status', [WorkoutSessionStatus::Completed, WorkoutSessionStatus::Skipped])
            ->with(['workoutExercises.exercise', 'workoutExercises.exerciseLog.exerciseSets'])
            ->orderByDesc('scheduled_at')
            ->first();

        return $session !== null ? $this->buildSessionSnapshot($session) : null;
    }

    private function buildSessionSnapshot(WorkoutSession $session): CoachSessionSnapshot
    {
        $snapshot = $session->prescription_context_snapshot;

        $exercises = $session->workoutExercises
            ->map(fn (WorkoutExercise $we) => $this->buildExerciseSnapshot($we))
            ->values()
            ->all();

        return new CoachSessionSnapshot(
            workoutSessionId: $session->id,
            status: $session->status,
            decidedFocus: $snapshot['decided_focus'] ?? null,
            goal: $snapshot['goal'] ?? null,
            scheduledAt: $session->scheduled_at,
            completedAt: $session->completed_at,
            exercises: $exercises,
        );
    }

    /**
     * Mismo criterio de derivación de outcome que D049 (sin ExerciseLog ->
     * Unreported; con log y sin sets -> Skipped; con log y sets -> Performed)
     * — ver docblock de la clase.
     */
    private function buildExerciseSnapshot(WorkoutExercise $workoutExercise): CoachExerciseSnapshot
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

        return new CoachExerciseSnapshot(
            exerciseId: $workoutExercise->exercise_id,
            name: $workoutExercise->exercise_snapshot['name'] ?? '',
            prescribedSets: $workoutExercise->prescribed_sets,
            prescribedReps: $workoutExercise->prescribed_reps,
            prescribedLoad: $workoutExercise->prescribed_load !== null ? (float) $workoutExercise->prescribed_load : null,
            prescribedDurationSeconds: $workoutExercise->prescribed_duration_seconds,
            // Misma fuente de verdad que TrainingEngine::prescribeExercise()
            // (Exercise::tracking_type) — nunca inferido de prescribedDurationSeconds.
            // exercise_id es nullOnDelete (ver migración de workout_exercises):
            // si la relación no resuelve es porque exercise_id ya es null, así
            // que este fallback nunca se ejercita en la práctica; se mantiene
            // solo como defensa, nunca como un segundo criterio real.
            trackingType: $workoutExercise->exercise?->tracking_type ?? TrackingType::RepsAndLoad,
            outcome: $outcome,
            actualSets: $sets,
            rpe: $log?->rpe,
            note: $log?->note,
        );
    }

    /**
     * @return array<int, ProgressionEvaluation>
     */
    private function evaluateProgressionsFor(?CoachSessionSnapshot $currentSession, TrainingHistoryContext $historyContext): array
    {
        if ($currentSession === null) {
            return [];
        }

        $evaluations = [];

        foreach ($currentSession->exercises as $exerciseSnapshot) {
            if ($exerciseSnapshot->exerciseId === null) {
                continue;
            }

            $evaluations[$exerciseSnapshot->exerciseId] = $this->progressionEvaluator->evaluate(
                $historyContext,
                $exerciseSnapshot->exerciseId,
                $exerciseSnapshot->trackingType,
            );
        }

        return $evaluations;
    }

    /**
     * Últimos 10 WhatsAppMessage (mismo límite que ya usa
     * App\Handlers\FallbackChatHandler para e-commerce), en orden
     * cronológico ascendente — contexto lingüístico, nunca fuente factual
     * (ver CoachFactsFormatter).
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function recentMessagesFor(ExecutionContext $context): array
    {
        // `latest('id')`, no `latest()` (created_at): varias filas pueden
        // compartir el mismo `created_at` a precisión de segundo cuando se
        // crean muy seguidas — `id` es el único desempate monotónico real.
        return WhatsAppMessage::where('tenant_id', $context->tenant->id)
            ->where('customer_phone', $context->message->from)
            ->latest('id')
            ->limit(self::RECENT_MESSAGES_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (WhatsAppMessage $message) => ['role' => $message->role, 'content' => $message->content])
            ->values()
            ->all();
    }
}
