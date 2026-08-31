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
 * `data['exercises']` incluye únicamente ejercicios sin ExerciseLog todavía
 * (`already_logged: false` para todos los presentes) — un ejercicio ya
 * reportado no vuelve a ofrecerse como destino de un nuevo reporte.
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
            ->whereNull('exerciseLog')
            ->map(fn ($workoutExercise) => [
                'workout_exercise_id' => $workoutExercise->id,
                'name' => $workoutExercise->exercise_snapshot['name'] ?? 'Ejercicio',
                'tracking_type' => $workoutExercise->prescribed_duration_seconds !== null ? 'time_based' : 'reps_and_load',
            ])
            ->values()
            ->all();

        return new ContextFragment(
            label: 'active_workout_session',
            data: [
                'workout_session_id' => $session->id,
                'unreported_exercises' => $exercises,
            ],
            source: 'db',
            confidence: 'confirmed',
        );
    }
}
