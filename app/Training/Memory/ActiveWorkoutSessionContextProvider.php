<?php

namespace App\Training\Memory;

use App\Core\Memory\ContextFragment;
use App\Core\Memory\ContextProviderInterface;
use App\Core\Messaging\ExecutionContext;
use App\Models\Contact;
use App\Training\Enums\WorkoutSessionStatus;

/**
 * Second real ContextProvider (Hito 6) — resuelve la WorkoutSession
 * pendiente (`scheduled`) del usuario, si existe, con únicamente los datos
 * necesarios para (a) construir el prompt de extracción de un reporte de
 * ejecución y (b) que TrainingHandler sepa qué ejercicios siguen sin
 * reportar. Nunca expone `exercise_snapshot` completo (instrucciones, etc.)
 * — solo lo mínimo para nombrar/identificar cada ejercicio.
 *
 * `data['unreported_exercises']` incluye ÚNICAMENTE ejercicios de bloque
 * principal (`Main`) sin `ExerciseLog` todavía — Preparación/Cooldown
 * NUNCA aparecen aquí (Hito R1/R2/R3): no piden reporte estructurado, y
 * ofrecerlos como destino de un reporte confundiría al extractor de IA y
 * al usuario. Ver `WorkoutExercise::requiresExecutionReport()`. Se
 * conserva EXCLUSIVAMENTE para permitir que un mensaje NOMBRE
 * explícitamente un Main anterior (corrección retroactiva por nombre) —
 * nunca como fuente de "cuál es el ejercicio actual".
 *
 * `data['front_exercise']` (corrección post-incidente de staging #33,
 * reemplaza a `pending_support_exercise` — único consumidor era
 * `TrainingHandler`, actualizado en el mismo cambio): representación
 * ÚNICA y unificada de "qué está viendo/resolviendo el usuario ahora
 * mismo", para CUALQUIER fase, no solo apoyo. Se deriva exclusivamente de
 * `WorkoutSession::frontExercise()` (única fuente de verdad — ver su
 * docblock): nunca de `exerciseLog`, nunca de
 * `isResolvedForSessionProgression()`, nunca de "primer Main sin log".
 * `null` si no hay sesión activa o nada se ha entregado todavía.
 * `requires_report` distingue Main (`true`, espera `ExecutionReportService`)
 * de Preparation/Cooldown (`false`, espera `SupportPhaseConfirmationDetector`)
 * — es la ÚNICA condición que `TrainingHandler` debe usar para decidir qué
 * detector aplica, en vez de inferirlo de listas separadas que pueden
 * desincronizarse entre sí.
 */
class ActiveWorkoutSessionContextProvider implements ContextProviderInterface
{
    public function provide(ExecutionContext $context): ContextFragment
    {
        $contact = Contact::where('tenant_id', $context->tenant->id)
            ->where('customer_phone', $context->message->from)
            ->first();

        $session = $contact?->workoutSessions()
            ->where('status', WorkoutSessionStatus::Scheduled)
            ->with('workoutExercises.exerciseLog')
            ->orderByDesc('scheduled_at')
            ->first();

        if ($session === null) {
            return new ContextFragment(
                label: 'active_workout_session',
                data: null,
                source: 'db',
                confidence: 'unknown',
            );
        }

        $exercises = $session->workoutExercises
            ->filter(fn ($workoutExercise) => $workoutExercise->requiresExecutionReport() && $workoutExercise->exerciseLog === null)
            ->map(fn ($workoutExercise) => [
                'workout_exercise_id' => $workoutExercise->id,
                'name' => $workoutExercise->exercise_snapshot['name'] ?? 'Ejercicio',
                'tracking_type' => $workoutExercise->prescribed_duration_seconds !== null ? 'time_based' : 'reps_and_load',
            ])
            ->values()
            ->all();

        $front = $session->frontExercise();

        $frontExerciseData = $front !== null ? [
            'workout_exercise_id' => $front->id,
            'exercise_id' => $front->exercise_id,
            'name' => $front->exercise_snapshot['name'] ?? 'Ejercicio',
            'phase' => $front->phase->value,
            'requires_report' => $front->requiresExecutionReport(),
        ] : null;

        return new ContextFragment(
            label: 'active_workout_session',
            data: [
                'workout_session_id' => $session->id,
                'unreported_exercises' => $exercises,
                'front_exercise' => $frontExerciseData,
            ],
            source: 'db',
            confidence: 'confirmed',
        );
    }
}
