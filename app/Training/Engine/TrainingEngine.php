<?php

namespace App\Training\Engine;

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrackingType;
use App\Training\Enums\TrainingLocation;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessDeniedException;
use App\Training\Support\TrainingAccessGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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

    /**
     * Hito 8.4: cuántas de las sesiones más recientes cuentan para penalizar
     * (nunca excluir) un ejercicio por repetido — ver sortCandidates(). Un
     * valor bajo a propósito: con el catálogo real actual (1 ejercicio en
     * producción, ver docs/DECISIONS.md D034) un valor alto dejaría la
     * anti-repetición sin ningún candidato "no repetido" para desempatar.
     */
    private const ANTI_REPETITION_LOOKBACK_SESSIONS = 2;

    private const DIFFICULTY_ORDER = [
        'beginner' => 0,
        'intermediate' => 1,
        'advanced' => 2,
    ];

    /**
     * Hito 8.4 — punto 9 aprobado: valores de arranque para un ejercicio SIN
     * historial propio todavía (ni una ejecución real de este contacto). Son
     * HEURÍSTICAS INICIALES DE PRODUCTO, revisables en cualquier momento sin
     * migración — NUNCA una prescripción científica universal ni una tabla
     * validada clínicamente. En cuanto existe una ejecución real, la
     * progresión por RPE (ver progressionFor()) gobierna sets/reps/carga/
     * duración para ESE ejercicio y contacto — estos valores dejan de
     * aplicar. `rest_seconds` no tiene mecanismo de progresión por historial
     * (nunca lo tuvo, ver Hito 4-8.3): siempre se deriva del objetivo
     * vigente, con o sin historial.
     *
     * Justificación direccional de cada objetivo (no una cita a un estudio
     * específico, un criterio de producto documentado para ser revisado):
     * - lose_weight: más repeticiones y menos descanso → mayor densidad de
     *   trabajo por sesión (énfasis metabólico/gasto calórico).
     * - build_muscle: descanso más largo → permite recuperar más carga entre
     *   series (énfasis en fuerza/hipertrofia con series de calidad).
     * - endurance: repeticiones/tiempo altos con descanso mínimo → prioriza
     *   sostener el esfuerzo, no la carga máxima.
     * - general_fitness: punto medio, igual al valor por defecto histórico
     *   de este motor antes de Hito 8.4.
     */
    private const GOAL_DEFAULTS = [
        'lose_weight' => ['sets' => 3, 'reps' => 15, 'rest_seconds' => 30, 'duration_seconds' => 40],
        'build_muscle' => ['sets' => 4, 'reps' => 10, 'rest_seconds' => 90, 'duration_seconds' => 30],
        'endurance' => ['sets' => 3, 'reps' => 18, 'rest_seconds' => 20, 'duration_seconds' => 45],
        'general_fitness' => ['sets' => 3, 'reps' => 10, 'rest_seconds' => 60, 'duration_seconds' => 30],
    ];

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

    public function __construct(
        private readonly TrainingAccessGate $accessGate,
        private readonly SafetyRestrictionResolver $safetyResolver,
    ) {}

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

        $exercises = $this->selectExercises($profile, $focus, $recentSessions, $contact);

        // Bloque 3 — Fundación Temporal (ver docs/DECISIONS.md D046):
        // capturado UNA sola vez. Conceptualmente es `prescribed_at` — el
        // instante en que TrainingEngine tomó la decisión de prescribir —
        // que NO es el mismo concepto que `scheduled_at` (el instante para
        // el que la sesión está prevista), aunque hoy coincidan por
        // construcción: este motor no soporta programación anticipada, así
        // que "decidido ahora" y "previsto para ahora" son, hoy, el mismo
        // número. Por eso se reutiliza la misma variable para ambos sin
        // cambiar el significado de `scheduled_at`. Si en el futuro se
        // introduce programación real (una capacidad temporal separada y
        // determinista, explícitamente fuera de este bloque), `prescribed_at`
        // deberá capturarse independientemente de `scheduled_at` — hoy no
        // se crea esa columna porque no existe ningún consumidor real que
        // la necesite fuera de `prescription_context_snapshot.generated_at`,
        // que ya cumple ese rol histórico.
        $generatedAt = now();

        $session = WorkoutSession::create([
            'contact_id' => $contact->id,
            'status' => WorkoutSessionStatus::Scheduled,
            'scheduled_at' => $generatedAt,
            'generated_by' => 'training_engine',
            'prescription_context_snapshot' => $profile->toPrescriptionContextSnapshot(
                $focus,
                $this->safetyResolver->activeSafetyBodyRegions($profile),
                $generatedAt,
            ),
        ]);

        foreach ($exercises as $index => $exercise) {
            $this->prescribeExercise($session, $exercise, $index + 1, $contact, $profile);
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
     * Hito 8.4 — Decide de la selección de ejercicios, en el orden de
     * prioridad aprobado: elegibilidad → foco → objetivo/nivel →
     * anti-repetición → determinismo.
     *
     * 1. Elegibilidad (filtro duro, nunca un puntaje): seguridad
     *    (restricciones vs. contraindicaciones) y equipamiento — ver
     *    isEligible(). Un ejercicio no elegible NUNCA entra a ningún nivel,
     *    sin importar cuánto matchee el foco.
     * 2. Foco: entre los elegibles, se clasifican en 3 niveles —
     *    primario (Exercise.primary_muscle/secondary_muscles intersecta
     *    TrainingProfile.primary_focus), secundario (ídem con
     *    secondary_focus), y general (el mecanismo de rotación por
     *    continuidad ya existente, ROTATIONS/decideFocus, sin cambios,
     *    usado como fallback cuando no hay foco declarado o se agotan los
     *    candidatos de foco). Un perfil sin foco declarado (primary_focus
     *    === []) se comporta exactamente igual que antes de Hito 8.4: solo
     *    existe el nivel general.
     * 3. Objetivo/nivel: dentro de un mismo nivel de foco, se prioriza el
     *    ejercicio cuya difficulty_level coincide con el experience_level
     *    del perfil (ver difficultyMatchRank()). El objetivo (goal) no
     *    tiene hoy una etiqueta propia por ejercicio en el catálogo — su
     *    efecto en la personalización es sobre la PRESCRIPCIÓN (sets/reps/
     *    descanso, ver GOAL_DEFAULTS), no sobre qué ejercicios se eligen;
     *    esa es una decisión de modelado explícita, documentada en
     *    docs/DECISIONS.md D034, no una omisión.
     * 4. Anti-repetición: SOLO desempata entre ejercicios ya empatados en
     *    foco y nivel — nunca puede hacer que un ejercicio de un nivel de
     *    foco inferior, o con peor ajuste de nivel, le gane a uno mejor por
     *    el solo hecho de ser distinto. Esto es exactamente la salvaguarda
     *    pedida: al ordenar primero por nivel de foco y luego por ajuste de
     *    nivel, la anti-repetición nunca alcanza a comparar dos ejercicios
     *    que no eran ya intercambiables en esas dos dimensiones.
     * 5. Determinismo: último desempate por id ascendente — la misma
     *    combinación de perfil/catálogo/historial produce siempre la misma
     *    sesión.
     *
     * La selección final es, simplemente, tomar los primeros
     * EXERCISES_PER_SESSION de [nivel primario ordenado, nivel secundario
     * ordenado, nivel general ordenado] concatenados en ese orden — el
     * orden de los niveles YA garantiza que el foco domina sobre todo lo
     * demás, sin necesitar pesos numéricos calibrados a mano.
     *
     * Garantía de foco (Hito 8.4, punto 5 aprobado): con primary_focus
     * declarado, se espera que al menos ceil(EXERCISES_PER_SESSION/2) de
     * los ejercicios elegidos vengan de los niveles primario+secundario. Si
     * el catálogo elegible no alcanza para cumplirla, se registra
     * TRAINING_FOCUS_FALLBACK — nunca se bloquea ni se inventa un ejercicio
     * para forzarla (ver docs/DECISIONS.md D034: catálogo real de
     * producción hoy tiene un único ejercicio activo, sin esta metadata).
     *
     * @return Collection<int, Exercise>
     */
    private function selectExercises(TrainingProfile $profile, string $focus, Collection $recentSessions, Contact $contact): Collection
    {
        $recentlyUsedExerciseIds = $recentSessions
            ->take(self::ANTI_REPETITION_LOOKBACK_SESSIONS)
            ->flatMap(fn (WorkoutSession $session) => $session->workoutExercises->pluck('exercise_id'))
            ->unique()
            ->values()
            ->all();

        $eligible = Exercise::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Exercise $exercise) => $this->isEligible($exercise, $profile));

        $primaryFocus = $profile->primary_focus ?? [];
        $secondaryFocus = $profile->secondary_focus ?? [];
        $generalMuscleGroups = explode(',', $focus);

        $primaryTier = collect();
        $secondaryTier = collect();
        $generalTier = collect();

        foreach ($eligible as $exercise) {
            $exerciseMuscles = array_values(array_filter(array_merge(
                [$exercise->primary_muscle?->value],
                $exercise->secondary_muscles ?? []
            )));

            if ($primaryFocus !== [] && array_intersect($exerciseMuscles, $primaryFocus) !== []) {
                $primaryTier->push($exercise);
            } elseif ($secondaryFocus !== [] && array_intersect($exerciseMuscles, $secondaryFocus) !== []) {
                $secondaryTier->push($exercise);
            } elseif (in_array($exercise->muscle_group, $generalMuscleGroups, true)) {
                $generalTier->push($exercise);
            }
            // Un ejercicio elegible que no matchea ni el foco declarado ni
            // el muscle_group de la rotación actual queda deliberadamente
            // fuera de los 3 niveles — no es candidato para ESTA sesión.
        }

        $primaryTier = $this->sortCandidates($primaryTier, $profile, $recentlyUsedExerciseIds);
        $secondaryTier = $this->sortCandidates($secondaryTier, $profile, $recentlyUsedExerciseIds);
        $generalTier = $this->sortCandidates($generalTier, $profile, $recentlyUsedExerciseIds);

        $focusSlotsAvailable = $primaryTier->count() + $secondaryTier->count();
        $minFocusSlots = (int) ceil(self::EXERCISES_PER_SESSION / 2);

        if ($primaryFocus !== [] && $focusSlotsAvailable < $minFocusSlots) {
            Log::info('TRAINING_FOCUS_FALLBACK', [
                'contact_id' => $contact->id,
                'primary_focus' => $primaryFocus,
                'secondary_focus' => $secondaryFocus,
                'focus_candidates_available' => $focusSlotsAvailable,
                'min_focus_slots_required' => $minFocusSlots,
            ]);
        }

        return $primaryTier->concat($secondaryTier)->concat($generalTier)
            ->take(self::EXERCISES_PER_SESSION)
            ->values();
    }

    /**
     * @param  array<int, int>  $recentlyUsedExerciseIds
     * @return Collection<int, Exercise>
     */
    private function sortCandidates(Collection $candidates, TrainingProfile $profile, array $recentlyUsedExerciseIds): Collection
    {
        return $candidates->sort(function (Exercise $a, Exercise $b) use ($profile, $recentlyUsedExerciseIds) {
            $levelDiff = $this->difficultyMatchRank($a, $profile) <=> $this->difficultyMatchRank($b, $profile);
            if ($levelDiff !== 0) {
                return $levelDiff;
            }

            $repeatA = in_array($a->id, $recentlyUsedExerciseIds, true) ? 1 : 0;
            $repeatB = in_array($b->id, $recentlyUsedExerciseIds, true) ? 1 : 0;
            if ($repeatA !== $repeatB) {
                return $repeatA <=> $repeatB;
            }

            return $a->id <=> $b->id;
        })->values();
    }

    /**
     * 0 = coincide exactamente con el nivel del perfil, 1 = un nivel de
     * diferencia (ej. intermediate pidió, el ejercicio es beginner o
     * advanced), 2 = sin dato o diferencia mayor.
     */
    private function difficultyMatchRank(Exercise $exercise, TrainingProfile $profile): int
    {
        $profileLevel = $profile->experience_level?->value;
        $exerciseLevel = $exercise->difficulty_level;

        if ($profileLevel === null || ! isset(self::DIFFICULTY_ORDER[$exerciseLevel]) || ! isset(self::DIFFICULTY_ORDER[$profileLevel])) {
            return 2;
        }

        if ($exerciseLevel === $profileLevel) {
            return 0;
        }

        $diff = abs(self::DIFFICULTY_ORDER[$exerciseLevel] - self::DIFFICULTY_ORDER[$profileLevel]);

        return $diff === 1 ? 1 : 2;
    }

    /**
     * Filtro duro de elegibilidad (nunca un puntaje): seguridad, ubicación y
     * equipamiento. `equipment_fully_equipped` (Hito 8.3) hace que CUALQUIER
     * ejercicio sea elegible en cuanto a equipo — declarar acceso amplio
     * significa que no vale la pena enumerar qué tiene exactamente.
     *
     * Hito de seguridad de restricciones: la comparación de seguridad ya
     * no lee los campos crudos de restricciones/contraindicaciones de
     * forma directa — ambos se resuelven a un contrato canónico vía
     * `SafetyRestrictionResolver`, la única pieza que conoce el mecanismo
     * legacy (texto libre) y el nuevo (`TrainingRestriction` estructurado).
     * Este motor sigue haciendo exactamente la misma intersección de
     * siempre, solo que sobre datos ya nivelados — no conoce
     * `TrainingRestriction`, `DeclaredHealthCondition`, `source`, `status`,
     * ni revisión humana.
     */
    private function isEligible(Exercise $exercise, TrainingProfile $profile): bool
    {
        $activeRestrictions = $this->safetyResolver->activeSafetyBodyRegions($profile);
        $exerciseSafetyTags = $this->safetyResolver->exerciseBodyRegions($exercise);

        if (array_intersect($activeRestrictions, $exerciseSafetyTags) !== []) {
            return false;
        }

        // Hito 9.0: training_location no tenía ningún consumidor real —
        // "outdoor" es la única ubicación con una consecuencia dura y
        // honesta de modelar: lo que el usuario POSEE (available_equipment/
        // equipment_fully_equipped) no es lo mismo que lo que tiene CONSIGO
        // en un parque. Un ejercicio que exige equipo queda inelegible sin
        // importar esos dos campos.
        if ($profile->training_location === TrainingLocation::Outdoor && $exercise->equipment_needed !== []) {
            return false;
        }

        $equipmentNeeded = $exercise->equipment_needed ?? [];

        if ($equipmentNeeded === [] || $profile->equipment_fully_equipped === true) {
            return true;
        }

        $available = $profile->available_equipment ?? [];

        return array_diff($equipmentNeeded, $available) === [];
    }

    private function prescribeExercise(WorkoutSession $session, Exercise $exercise, int $order, Contact $contact, TrainingProfile $profile): WorkoutExercise
    {
        $progression = $this->progressionFor($exercise, $contact, $profile);

        return WorkoutExercise::create([
            'workout_session_id' => $session->id,
            'exercise_id' => $exercise->id,
            'order' => $order,
            'prescribed_sets' => $progression['sets'],
            'prescribed_reps' => $progression['reps'],
            'prescribed_load' => $progression['load'],
            'prescribed_duration_seconds' => $progression['duration_seconds'],
            'rest_seconds' => $progression['rest_seconds'],
            'exercise_snapshot' => $exercise->toSnapshot(),
        ]);
    }

    /**
     * Regla de progresión mínima: si la última ejecución reportada de este
     * ejercicio tuvo un RPE bajo (esfuerzo percibido manejable) y se
     * completó, se sube ligeramente la carga/duración. Si no hay ejecución
     * previa, se usan los valores de arranque del objetivo vigente
     * (GOAL_DEFAULTS, Hito 8.4 — antes de esto eran constantes fijas sin
     * relación con el objetivo). Nunca se recalculan WorkoutExercise ya
     * creados — esto solo afecta a la sesión nueva.
     *
     * `rest_seconds` nunca tuvo progresión por historial (ver GOAL_DEFAULTS)
     * — siempre refleja el objetivo vigente, exista o no ejecución previa.
     *
     * @return array{sets: ?int, reps: ?int, load: ?float, duration_seconds: ?int, rest_seconds: int}
     */
    private function progressionFor(Exercise $exercise, Contact $contact, TrainingProfile $profile): array
    {
        $isTimeBased = $exercise->tracking_type === TrackingType::TimeBased;
        $goalDefaults = self::GOAL_DEFAULTS[$profile->goal?->value] ?? self::GOAL_DEFAULTS['general_fitness'];

        $lastExecution = WorkoutExercise::query()
            ->where('exercise_id', $exercise->id)
            ->whereHas('workoutSession', fn ($query) => $query->where('contact_id', $contact->id))
            ->whereHas('exerciseLog')
            ->with('exerciseLog.exerciseSets')
            ->latest('id')
            ->first();

        if ($lastExecution === null || $lastExecution->exerciseLog === null) {
            return $isTimeBased
                ? ['sets' => $goalDefaults['sets'], 'reps' => null, 'load' => null, 'duration_seconds' => $goalDefaults['duration_seconds'], 'rest_seconds' => $goalDefaults['rest_seconds']]
                : ['sets' => $goalDefaults['sets'], 'reps' => $goalDefaults['reps'], 'load' => null, 'duration_seconds' => null, 'rest_seconds' => $goalDefaults['rest_seconds']];
        }

        $log = $lastExecution->exerciseLog;
        $topSet = $log->exerciseSets->sortByDesc('actual_load')->first() ?? $log->exerciseSets->first();
        $shouldProgress = $log->rpe !== null && $log->rpe <= self::PROGRESSION_RPE_THRESHOLD;

        if ($isTimeBased) {
            $lastDuration = $topSet?->actual_duration_seconds ?? $lastExecution->prescribed_duration_seconds ?? $goalDefaults['duration_seconds'];

            return [
                'sets' => $lastExecution->prescribed_sets ?? $goalDefaults['sets'],
                'reps' => null,
                'load' => null,
                'duration_seconds' => $shouldProgress ? $lastDuration + 10 : $lastDuration,
                'rest_seconds' => $goalDefaults['rest_seconds'],
            ];
        }

        $lastLoad = $topSet?->actual_load ?? $lastExecution->prescribed_load;
        $lastReps = $topSet?->actual_reps ?? $lastExecution->prescribed_reps ?? $goalDefaults['reps'];

        return [
            'sets' => $lastExecution->prescribed_sets ?? $goalDefaults['sets'],
            'reps' => $lastReps,
            'load' => $lastLoad !== null && $shouldProgress ? ((float) $lastLoad + 2.5) : ($lastLoad !== null ? (float) $lastLoad : null),
            'duration_seconds' => null,
            'rest_seconds' => $goalDefaults['rest_seconds'],
        ];
    }
}
