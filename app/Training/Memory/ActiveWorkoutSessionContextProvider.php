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
 * al usuario. Ver `WorkoutExercise::requiresExecutionReport()`.
 *
 * `data['pending_support_exercise']` (nuevo, Hito R1/R2/R3): no-null
 * únicamente cuando el ÚLTIMO `WorkoutExercise` entregado (`delivered_at`
 * no nulo, mayor `order`) es un Preparation/Cooldown, esperando la
 * confirmación explícita del usuario para avanzar (ver `TrainingHandler`/
 * `SupportPhaseConfirmationDetector`). Deliberadamente NO se deriva de
 * `isResolvedForSessionProgression()`: para Preparation/Cooldown ese método
 * ya es `true` en cuanto se entrega (ver su docblock en `WorkoutExercise`),
 * así que usarlo aquí saltaría de largo el ejercicio que el usuario tiene
 * frente a él ahora mismo. `null` si no hay sesión activa, si nada se ha
 * entregado todavía, o si el último entregado es Main (flujo normal de
 * reporte, ver `unreported_exercises` arriba).
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

        // El "frente" real de la cola de entrega (Hito R1/R2/R3) NO es "el
        // primero sin resolver para progresión" — para Preparation/Cooldown,
        // `isResolvedForSessionProgression()` ya es `true` en cuanto se
        // entrega (ver docblock de ese método), así que ese criterio
        // saltaría de largo el ejercicio de apoyo que el usuario tiene
        // frente a él ahora mismo, esperando su confirmación. El frente real
        // es, en cambio, el ÚLTIMO ejercicio entregado por `order` — el
        // siguiente en la cola nunca se entrega hasta que este se confirme
        // (o, si es Main, hasta que se reporte).
        $frontExercise = $session->workoutExercises
            ->filter(fn ($we) => $we->delivered_at !== null)
            ->sortByDesc('order')
            ->first();

        $pendingSupportExercise = ($frontExercise !== null && ! $frontExercise->requiresExecutionReport())
            ? ['workout_exercise_id' => $frontExercise->id, 'phase' => $frontExercise->phase->value]
            : null;

        return new ContextFragment(
            label: 'active_workout_session',
            data: [
                'workout_session_id' => $session->id,
                'unreported_exercises' => $exercises,
                'pending_support_exercise' => $pendingSupportExercise,
            ],
            source: 'db',
            confidence: 'confirmed',
        );
    }
}
