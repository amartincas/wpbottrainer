<?php

namespace App\Training\Engine;

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrackingType;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\TrainingAccessDeniedException;
use App\Training\Support\TrainingAccessGate;
use Illuminate\Support\Collection;

/**
 * Paso "Decide" del patrón Extract → Decide → Narrate (ver docs/ARCHITECTURE.md).
 * Determinista: nunca invoca al LLM ni acepta que este decida qué
 * entrenamiento generar, ni sobre qué ejercicios/series/cargas prescribir.
 *
 * `TrainingProfile.next_focus` es una señal de continuidad, no una
 * autoridad — este servicio evalúa sesión pendiente, recuperación/descanso,
 * sesión omitida, restricciones y disponibilidad de equipo ANTES de aceptar
 * la sugerencia de next_focus. Ver docs/DECISIONS.md (Hito 4).
 *
 * No conoce WhatsApp, LLM, ni Handlers — recibe un Contact y produce una
 * WorkoutSession. La integración conversacional (Extract de disponibilidad,
 * narración de resultados) llega con el primer Handler real de Training, en
 * un hito posterior.
 */
class TrainingEngine
{
    private const EXERCISES_PER_SESSION = 3;

    private const RECOVERY_NEGLECT_DAYS = 5;

    private const RECENT_SESSIONS_LOOKBACK = 5;

    private const PROGRESSION_RPE_THRESHOLD = 7;

    private const DEFAULT_SETS = 3;

    private const DEFAULT_REPS = 10;

    private const DEFAULT_TIME_BASED_SECONDS = 30;

    private const DEFAULT_REST_SECONDS = 60;

    /**
     * Grupos musculares que componen cada "foco" posible por tipo de
     * rotación. Expresados directamente en el vocabulario de
     * Exercise.muscle_group (listas separadas por coma, en orden
     * alfabético) para no necesitar una tabla/mapa de traducción aparte
     * entre "push/pull/legs" y los grupos musculares reales —
     * TrainingProfile.next_focus y el foco histórico de una WorkoutSession
     * (derivado de sus exercise_snapshot vía focusOf()) usan siempre el
     * mismo vocabulario y el mismo orden alfabético, para que ambas cadenas
     * sean directamente comparables con === sin necesitar normalización
     * adicional en cada comparación.
     */
    private const ROTATIONS = [
        'full_body' => ['arms,back,chest,core,legs,shoulders'],
        'upper_lower' => ['arms,back,chest,shoulders', 'core,legs'],
        'push_pull_legs' => ['arms,chest,shoulders', 'arms,back', 'core,legs'],
    ];

    public function __construct(private readonly TrainingAccessGate $accessGate) {}

    /**
     * Decide la próxima WorkoutSession para un Contact. Si ya existe una
     * sesión pendiente (status=scheduled), la devuelve sin cambios —
     * idempotente para "qué toca hoy".
     *
     * @throws TrainingAccessDeniedException si el Gate bloquea el acceso
     *         (sin acceso comercial vigente, o perfil marcado por seguridad).
     */
    public function decideNextSession(Contact $contact): WorkoutSession
    {
        $gateResult = $this->accessGate->authorize($contact);

        if (! $gateResult->allowed) {
            throw new TrainingAccessDeniedException($gateResult->reason);
        }

        $profile = $contact->trainingProfile;

        if ($profile === null) {
            throw new \RuntimeException('Cannot generate a workout session without a TrainingProfile.');
        }

        $pending = $contact->workoutSessions()
            ->where('status', WorkoutSessionStatus::Scheduled)
            ->orderByDesc('scheduled_at')
            ->first();

        if ($pending !== null) {
            return $pending;
        }

        $recentSessions = $contact->workoutSessions()
            ->whereIn('status', [WorkoutSessionStatus::Completed, WorkoutSessionStatus::Skipped])
            ->with('workoutExercises')
            ->orderByDesc('scheduled_at')
            ->limit(self::RECENT_SESSIONS_LOOKBACK)
            ->get();

        $focus = $this->decideFocus($profile, $recentSessions);

        $exercises = $this->selectExercises($profile, $focus);

        $session = WorkoutSession::create([
            'contact_id' => $contact->id,
            'status' => WorkoutSessionStatus::Scheduled,
            'scheduled_at' => now(),
            'generated_by' => 'training_engine',
        ]);

        foreach ($exercises as $index => $exercise) {
            $this->prescribeExercise($session, $exercise, $index + 1, $contact);
        }

        $profile->update(['next_focus' => $this->nextInRotation($focus, $profile->split_type)]);

        return $session->load('workoutExercises');
    }

    /**
     * Orden de prioridad: recuperación > sesión omitida > evitar repetir el
     * último enfoque completado > señal de continuidad (next_focus).
     * Ninguna de estas reglas es el LLM decidiendo — todo el árbol es
     * determinista sobre datos ya persistidos.
     */
    private function decideFocus(TrainingProfile $profile, Collection $recentSessions): string
    {
        $neglected = $this->mostNeglectedFocus($profile, $recentSessions);
        if ($neglected !== null) {
            return $neglected;
        }

        $lastSession = $recentSessions->first();

        if ($lastSession !== null && $lastSession->status === WorkoutSessionStatus::Skipped) {
            return $this->focusOf($lastSession) ?? $this->defaultFocus($profile);
        }

        $suggested = $profile->next_focus ?: $this->defaultFocus($profile);
        $lastFocus = $lastSession !== null ? $this->focusOf($lastSession) : null;

        if ($lastFocus !== null && $lastFocus === $suggested) {
            return $this->nextInRotation($suggested, $profile->split_type);
        }

        return $suggested;
    }

    private function mostNeglectedFocus(TrainingProfile $profile, Collection $recentSessions): ?string
    {
        $rotation = self::ROTATIONS[$profile->split_type->value] ?? self::ROTATIONS['full_body'];

        if (count($rotation) <= 1) {
            // full_body: no hay nada que rotar, por lo tanto nada que descuidar.
            return null;
        }

        foreach ($rotation as $candidateFocus) {
            $lastTrained = $recentSessions->first(
                fn (WorkoutSession $session) => $this->focusOf($session) === $candidateFocus
            );

            $daysSince = $lastTrained !== null
                ? $lastTrained->scheduled_at->diffInDays(now())
                : null;

            if ($daysSince === null || $daysSince > self::RECOVERY_NEGLECT_DAYS) {
                return $candidateFocus;
            }
        }

        return null;
    }

    private function defaultFocus(TrainingProfile $profile): string
    {
        $rotation = self::ROTATIONS[$profile->split_type->value] ?? self::ROTATIONS['full_body'];

        return $rotation[0];
    }

    /**
     * Deriva el foco que representó una WorkoutSession pasada a partir de
     * los grupos musculares realmente registrados en su exercise_snapshot —
     * nunca desde el Exercise actual (ver docs/DECISIONS.md, inmutabilidad
     * histórica). No requiere una columna "focus" adicional en WorkoutSession.
     */
    private function focusOf(WorkoutSession $session): ?string
    {
        $muscleGroups = $session->workoutExercises
            ->pluck('exercise_snapshot.muscle_group')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($muscleGroups->isEmpty()) {
            return null;
        }

        return $muscleGroups->implode(',');
    }

    private function nextInRotation(string $currentFocus, SplitType $splitType): string
    {
        $rotation = self::ROTATIONS[$splitType->value] ?? self::ROTATIONS['full_body'];

        $index = array_search($currentFocus, $rotation, true);

        if ($index === false) {
            return $rotation[0];
        }

        return $rotation[($index + 1) % count($rotation)];
    }

    /**
     * @return Collection<int, Exercise>
     */
    private function selectExercises(TrainingProfile $profile, string $focus): Collection
    {
        $muscleGroups = explode(',', $focus);

        return Exercise::query()
            ->where('is_active', true)
            ->whereIn('muscle_group', $muscleGroups)
            ->get()
            ->filter(fn (Exercise $exercise) => $this->isSafeForProfile($exercise, $profile))
            ->take(self::EXERCISES_PER_SESSION)
            ->values();
    }

    private function isSafeForProfile(Exercise $exercise, TrainingProfile $profile): bool
    {
        $restrictions = $profile->restrictions ?? [];
        $contraindications = $exercise->contraindications ?? [];

        if (array_intersect($restrictions, $contraindications) !== []) {
            return false;
        }

        $equipmentNeeded = $exercise->equipment_needed ?? [];

        if ($equipmentNeeded === []) {
            return true;
        }

        $available = $profile->available_equipment ?? [];

        return array_diff($equipmentNeeded, $available) === [];
    }

    private function prescribeExercise(WorkoutSession $session, Exercise $exercise, int $order, Contact $contact): WorkoutExercise
    {
        $progression = $this->progressionFor($exercise, $contact);

        return WorkoutExercise::create([
            'workout_session_id' => $session->id,
            'exercise_id' => $exercise->id,
            'order' => $order,
            'prescribed_sets' => $progression['sets'],
            'prescribed_reps' => $progression['reps'],
            'prescribed_load' => $progression['load'],
            'prescribed_duration_seconds' => $progression['duration_seconds'],
            'rest_seconds' => self::DEFAULT_REST_SECONDS,
            'exercise_snapshot' => $exercise->toSnapshot(),
        ]);
    }

    /**
     * Regla de progresión mínima: si la última ejecución reportada de este
     * ejercicio tuvo un RPE bajo (esfuerzo percibido manejable) y se
     * completó, se sube ligeramente la carga/duración. Si no hay ejecución
     * previa, se usa un valor conservador por defecto. Nunca se recalculan
     * WorkoutExercise ya creados — esto solo afecta a la sesión nueva.
     *
     * @return array{sets: ?int, reps: ?int, load: ?float, duration_seconds: ?int}
     */
    private function progressionFor(Exercise $exercise, Contact $contact): array
    {
        $isTimeBased = $exercise->tracking_type === TrackingType::TimeBased;

        $lastExecution = WorkoutExercise::query()
            ->where('exercise_id', $exercise->id)
            ->whereHas('workoutSession', fn ($query) => $query->where('contact_id', $contact->id))
            ->whereHas('exerciseLog')
            ->with('exerciseLog.exerciseSets')
            ->latest('id')
            ->first();

        if ($lastExecution === null || $lastExecution->exerciseLog === null) {
            return $isTimeBased
                ? ['sets' => self::DEFAULT_SETS, 'reps' => null, 'load' => null, 'duration_seconds' => self::DEFAULT_TIME_BASED_SECONDS]
                : ['sets' => self::DEFAULT_SETS, 'reps' => self::DEFAULT_REPS, 'load' => null, 'duration_seconds' => null];
        }

        $log = $lastExecution->exerciseLog;
        $topSet = $log->exerciseSets->sortByDesc('actual_load')->first() ?? $log->exerciseSets->first();
        $shouldProgress = $log->rpe !== null && $log->rpe <= self::PROGRESSION_RPE_THRESHOLD;

        if ($isTimeBased) {
            $lastDuration = $topSet?->actual_duration_seconds ?? $lastExecution->prescribed_duration_seconds ?? self::DEFAULT_TIME_BASED_SECONDS;

            return [
                'sets' => $lastExecution->prescribed_sets ?? self::DEFAULT_SETS,
                'reps' => null,
                'load' => null,
                'duration_seconds' => $shouldProgress ? $lastDuration + 10 : $lastDuration,
            ];
        }

        $lastLoad = $topSet?->actual_load ?? $lastExecution->prescribed_load;
        $lastReps = $topSet?->actual_reps ?? $lastExecution->prescribed_reps ?? self::DEFAULT_REPS;

        return [
            'sets' => $lastExecution->prescribed_sets ?? self::DEFAULT_SETS,
            'reps' => $lastReps,
            'load' => $lastLoad !== null && $shouldProgress ? ((float) $lastLoad + 2.5) : ($lastLoad !== null ? (float) $lastLoad : null),
            'duration_seconds' => null,
        ];
    }
}
