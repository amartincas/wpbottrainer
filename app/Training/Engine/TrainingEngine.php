<?php

namespace App\Training\Engine;

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\Equipment;
use App\Training\Enums\HistoryExerciseOutcome;
use App\Training\Enums\ProgressionDecision;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrackingType;
use App\Training\Enums\TrainingLocation;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\DurationEstimator;
use App\Training\Support\HistoryExerciseEntry;
use App\Training\Support\HistorySetEntry;
use App\Training\Support\ProgressionEvaluation;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\RequestedFocusGroup;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessDeniedException;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrainingCatalogInsufficientException;
use App\Training\Support\TrainingHistoryContext;
use App\Training\Support\TrainingHistoryContextProvider;
use App\Training\Support\TrainingPreferenceResolver;
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
    /**
     * Guard rail TÉCNICO sobre la cantidad calculada dinámicamente en
     * `exercisesForTargetDuration()` — nunca una regla de producto ("la
     * sesión debe tener entre 1 y 15 ejercicios" no es una decisión de
     * entrenamiento, es solo un límite de cordura ante una configuración de
     * Tenant fuera de rango). La cantidad real ya no es una constante fija
     * — se deriva de `Tenant.target_session_duration_minutes` vía
     * `DurationEstimator` (ver App\Training\Support\DurationEstimator).
     */
    private const MIN_EXERCISES_PER_SESSION = 1;

    private const MAX_EXERCISES_PER_SESSION = 15;

    private const RECOVERY_NEGLECT_DAYS = 5;

    private const RECENT_SESSIONS_LOOKBACK = 5;

    /**
     * Hito — Exercise Variety & Selection (MVP). Base del decaimiento
     * exponencial de `varietyScore()` — ver ese método para la fórmula
     * completa. `0.6` es un PARÁMETRO OPERATIVO ajustable del MVP, elegido
     * para que la pendiente entre sesiones sea suave y perceptible dentro
     * de la ventana de `RECENT_SESSIONS_LOOKBACK` (posición 0 → 1.0,
     * posición 4 → 0.1296) — NO representa ninguna recomendación
     * científica de recuperación, aprendizaje motor ni periodización.
     * Ajustable sin migración, igual que `GOAL_DEFAULTS`.
     */
    private const VARIETY_DECAY = 0.6;

    /**
     * Hito R1/R2/R3 — decaimiento de variedad para preparación/cooldown,
     * DISTINTO del de R1 (`VARIETY_DECAY`), nunca el mismo valor. `0.0`
     * para Preparation ("variedad mínima/consistente", Decisión #6 del
     * diseño aprobado): un calentamiento repetido sesión tras sesión es
     * deseable, no un defecto — con decay=0.0, `0.0 ** posición` solo
     * penaliza repetir EXACTAMENTE el ejercicio de la sesión
     * inmediatamente anterior (posición 0, donde `0.0**0=1.0`), nunca más
     * atrás. `0.3` para Cooldown ("ventana corta", Decisión #7): memoria
     * más corta que R1 pero no nula.
     */
    private const PREPARATION_VARIETY_DECAY = 0.0;

    private const COOLDOWN_VARIETY_DECAY = 0.3;

    /**
     * Hito R1/R2/R3 — estimación de producto para un ejercicio de
     * preparación/cooldown: un solo bloque continuo, sin series ni
     * descanso estructurado (a diferencia de R1, que sí los tiene vía
     * `numericPrescriptionFor()`). Misma naturaleza que
     * `AVERAGE_SET_EXECUTION_SECONDS` de `DurationEstimator` — heurística
     * ajustable sin migración, NUNCA una verdad fisiológica.
     */
    private const SUPPORT_EXERCISE_DURATION_SECONDS = 90;

    /**
     * Hito R1/R2/R3 — PISO mínimo (nunca una distribución exacta) del
     * presupuesto de tiempo total para el bloque principal (R1). Actúa
     * como guardrail defensivo: con los objetivos de conteo ya aprobados
     * (ver `planSupportBudget()`) y `SUPPORT_EXERCISE_DURATION_SECONDS`
     * actual, R1 normalmente recibe bastante más del 70% — este piso solo
     * se activa si la configuración cambia en el futuro.
     */
    private const MIN_MAIN_BUDGET_RATIO = 0.70;

    /**
     * Hito R1/R2/R3 — vocabulario base de cada fase de apoyo, verificado
     * contra el catálogo real (Audit funcional de roles): un ejercicio
     * entra al pool de una fase si su `exercise_type` intersecta este
     * conjunto — nunca una regla de dos niveles "condicional solo si
     * acompaña a base" (esa formulación se demostró redundante: se reduce
     * exactamente a esta intersección simple).
     */
    private const PREPARATION_TYPES = ['warmup', 'mobility'];

    private const COOLDOWN_TYPES = ['cooldown', 'stretching'];

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
     * progresión (Bloque 8, ver numericPrescriptionFor()) gobierna sets/reps/
     * carga/duración para ESE ejercicio y contacto — estos valores dejan de
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
        private readonly TrainingHistoryContextProvider $historyProvider,
        private readonly ProgressionEvaluator $progressionEvaluator,
        private readonly DurationEstimator $durationEstimator,
        private readonly TrainingPreferenceResolver $preferenceResolver,
    ) {}

    /**
     * Decide la próxima WorkoutSession para un Contact. Si ya existe una
     * sesión pendiente (status=scheduled), la devuelve sin cambios —
     * idempotente para "qué toca hoy".
     *
     *
     * @param  ?array<int, RequestedFocusGroup>  $requestedFocus  Hito B1
     *                                                            (Requested Focus) — petición PUNTUAL de esta sesión (ej.
     *                                                            "pecho y piernas"), ya normalizada por
     *                                                            `RequestedFocusTermMapper` — nunca texto libre, nunca decidida
     *                                                            por el LLM. `null` (default) preserva EXACTAMENTE el
     *                                                            comportamiento anterior a este hito: ningún caller existente
     *                                                            necesita cambiar. Nunca modifica
     *                                                            `TrainingProfile.primary_focus`/`secondary_focus`/`next_focus`
     *                                                            — ver `$autonomousFocus` más abajo, que sigue siendo la única
     *                                                            fuente de `next_focus`.
     *
     * @throws TrainingAccessDeniedException si el Gate bloquea el acceso
     *                                       (sin acceso comercial vigente, o perfil marcado por seguridad).
     * @throws TrainingCatalogInsufficientException si el catálogo elegible
     *                                              no produce ni un solo ejercicio de bloque principal (Main) —
     *                                              ninguna WorkoutSession se crea en ese caso.
     */
    public function decideNextSession(Contact $contact, ?array $requestedFocus = null): WorkoutSession
    {
        if ($requestedFocus === []) {
            // Un array vacío es semánticamente idéntico a "no se solicitó
            // nada" — nunca se trata como un estado distinto (evita, entre
            // otras cosas, una división por cero en el reparto de slots).
            $requestedFocus = null;
        }

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

        // Hito B2 (auditoría B2.2, Hipótesis 2 confirmada) — `Superseded` SÍ
        // participa aquí, igual que `Completed`/`Skipped`: esta colección
        // alimenta tanto `varietyScore()` (mide EXPOSICIÓN — qué ejercicios
        // ya se le mostraron al usuario, nunca si los ejecutó; una sesión
        // reemplazada tuvo `WorkoutExercise` reales entregados, ver
        // `varietyScore()`) como `mostNeglectedFocus()`/`decideFocus()`. Este
        // último merece la aclaración explícita del diseño aprobado (Sección
        // 18): `decideFocus()` solo dispara su reintento de foco cuando
        // `$lastSession->status === WorkoutSessionStatus::Skipped` —una
        // comparación estricta, nunca "distinto de Completed"— así que
        // agregar `Superseded` a este `whereIn` NUNCA activa esa rama
        // especial de `Skipped` para una sesión `Superseded`: cae, sin
        // ningún código adicional, en el mismo tratamiento por defecto que
        // ya reciben las sesiones `Completed` (avanza la rotación si
        // corresponde) — exactamente lo pedido: "Superseded no significa
        // 'no se hizo, reinténtalo', significa 'el usuario pidió otra
        // cosa'".
        $recentSessions = $contact->workoutSessions()
            ->whereIn('status', [
                WorkoutSessionStatus::Completed,
                WorkoutSessionStatus::Skipped,
                WorkoutSessionStatus::Superseded,
            ])
            ->with('workoutExercises')
            ->orderByDesc('scheduled_at')
            ->limit(self::RECENT_SESSIONS_LOOKBACK)
            ->get();

        // Hito B1 (Requested Focus) — $autonomousFocus (el foco que la
        // rotación habría decidido normalmente) SIEMPRE se calcula, exista o
        // no $requestedFocus: decideFocus() no depende de éste en absoluto
        // (no lee primary_focus/secondary_focus), así que no hay ningún
        // costo evitado al omitirlo, y next_focus (al final de este método)
        // debe poder derivarse de él sin importar qué se usó para elegir los
        // ejercicios de ESTA sesión.
        $autonomousFocus = $this->decideFocus($profile, $recentSessions);

        // Hito R1/R2/R3 — UNA sola lectura del catálogo ACTIVO, reutilizada
        // por las 3 fases (evita 3 consultas completas idénticas).
        // `$activePool` (sin filtrar por elegibilidad) se conserva además
        // para el Hito B1: diagnosticar si un grupo de requested_focus sin
        // candidatos elegibles se debe a que el catálogo no lo tiene, o a
        // que Safety/Equipment lo excluyó (ver
        // selectExercisesForRequestedFocus()) — nunca participa en la
        // selección real, solo en ese diagnóstico.
        $activePool = Exercise::query()->where('is_active', true)->get();

        // `isEligible()` no depende de la fase — mismo filtro de seguridad/
        // equipo para R1/R2/R3, sin excepción. NUNCA se modifica para
        // incorporar Preference (Hito B3, Regla 13 del diseño aprobado) —
        // el filtro de preferencia es un paso SEPARADO, aplicado a
        // continuación.
        $eligiblePool = $activePool->filter(fn (Exercise $exercise) => $this->isEligible($exercise, $profile));

        // Hito B3 (Preferencias persistentes, diseño v3 FINAL) — paso 3 del
        // pipeline (Safety -> Eligibility -> Preference -> Focus -> ...):
        // reduce el pool AÚN MÁS, después de Safety/Equipment y antes de
        // cualquier lógica de foco/tiers. `$eligiblePool` (solo Safety+
        // Equipment) se conserva sin tocar para el diagnóstico de 3 vías de
        // `requestedFocusCoverage()` (catalog / safety_or_equipment /
        // preference) — la selección real siempre usa
        // `$preferenceFilteredPool`.
        $excludedByPreference = $this->preferenceResolver->excludedIdentifiersFor($contact);
        $preferenceFilteredPool = $eligiblePool->reject(
            fn (Exercise $exercise) => $this->isExcludedByPreference($exercise, $excludedByPreference)
        );
        $appliedPreferences = $this->computeAppliedPreferences($excludedByPreference, $eligiblePool);

        [$targetPrepCount, $targetCooldownCount, $maxSupportSlots, $mainBudgetMinutes] = $this->planSupportBudget($contact->tenant);

        // R1 selecciona PRIMERO (autoridad de prescripción, Decisión #8 del
        // diseño aprobado) — nunca se ve limitado por lo que R2/R3 tomen.
        //
        // Hito B1 — $requestedFocus === null ejecuta EXACTAMENTE el flujo
        // anterior a este hito (selectExercises(), sin cambios). Solo cuando
        // hay una petición puntual se construyen tiers por GRUPO (nunca un
        // único primaryTier plano — ver docblock de
        // selectExercisesForRequestedFocus()).
        if ($requestedFocus === null) {
            $mainExercises = $this->selectExercises($profile, $autonomousFocus, $recentSessions, $contact, $preferenceFilteredPool, $mainBudgetMinutes);
            $requestedFocusCoverage = [];
        } else {
            [$mainExercises, $requestedFocusCoverage] = $this->selectExercisesForRequestedFocus(
                $profile, $requestedFocus, $autonomousFocus, $recentSessions, $contact, $preferenceFilteredPool, $eligiblePool, $activePool, $mainBudgetMinutes,
            );
        }

        // Una WorkoutSession NUNCA se crea sin al menos 1 ejercicio de
        // bloque principal — se lanza ANTES de WorkoutSession::create(),
        // así que ninguna fila llega a persistirse.
        if ($mainExercises->isEmpty()) {
            throw new TrainingCatalogInsufficientException;
        }

        $usedIds = $mainExercises->pluck('id');

        // Preparación: hasta targetPrepCount, acotado por maxSupportSlots.
        // Hito B3 — usa el pool YA reducido por preferencia (mismo criterio
        // que R1): una preferencia declarada excluye también de Preparation/
        // Cooldown, nunca solo de Main.
        $prepExercises = $this->selectPreparationExercises(
            $profile, $recentSessions, min($targetPrepCount, $maxSupportSlots),
            $preferenceFilteredPool->reject(fn (Exercise $exercise) => $usedIds->contains($exercise->id)),
        );
        $usedIds = $usedIds->merge($prepExercises->pluck('id'));

        // Cooldown: se queda con lo que Preparación no usó del presupuesto
        // de slots — implementa "si solo hay capacidad para 1, priorizar
        // Preparation; si Preparation no tiene candidatos reales, el slot
        // libre pasa a Cooldown" de forma natural (sin chequeo previo de
        // existencia: Preparación ya devolvió lo que realmente encontró).
        $remainingSlots = max(0, $maxSupportSlots - $prepExercises->count());
        $cooldownExercises = $this->selectCooldownExercises(
            $profile, $recentSessions, min($targetCooldownCount, $remainingSlots),
            $preferenceFilteredPool->reject(fn (Exercise $exercise) => $usedIds->contains($exercise->id)),
        );

        // Bloque 8 (D051): el contexto histórico se construye UNA sola vez
        // por generación, después de que la selección de ejercicios ya está
        // cerrada — nunca para el pool completo de candidatos, solo para los
        // pocos ya seleccionados que se prescribirán a continuación. Solo
        // R1 lo usa (R2/R3 nunca invocan ProgressionEvaluator).
        $historyContext = $this->historyProvider->build($contact);

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
                $autonomousFocus,
                $this->safetyResolver->activeSafetyBodyRegions($profile),
                $generatedAt,
                $requestedFocus !== null
                    ? array_map(fn (RequestedFocusGroup $group) => ['key' => $group->key, 'muscles' => $group->muscles], $requestedFocus)
                    : [],
                $requestedFocusCoverage,
                $appliedPreferences,
            ),
        ]);

        // Orden GLOBAL y continuo: Preparation → Main → Cooldown — nunca se
        // reinicia por fase. La ENTREGA (TrainingHandler, por `order`) usa
        // exactamente este orden; la SELECCIÓN (arriba) fue en un orden
        // distinto (R1 primero), sin relación entre ambos conceptos.
        $order = 1;

        foreach ($prepExercises as $exercise) {
            $this->prescribeSupportExercise($session, $exercise, $order++, WorkoutExercisePhase::Preparation);
        }

        foreach ($mainExercises as $exercise) {
            $this->prescribeExercise($session, $exercise, $order++, $historyContext, $profile);
        }

        foreach ($cooldownExercises as $exercise) {
            $this->prescribeSupportExercise($session, $exercise, $order++, WorkoutExercisePhase::Cooldown);
        }

        // Hito B1 — REGLA OBLIGATORIA: next_focus deriva EXCLUSIVAMENTE de
        // $autonomousFocus, nunca de $requestedFocus. requested_focus es una
        // petición de UNA sesión, no una preferencia persistente — si
        // avanzara la rotación, una petición puntual de "brazos" hoy
        // desviaría permanentemente qué le toca entrenar mañana sin que el
        // usuario lo haya pedido. Ver tests explícitos contra esta regresión.
        $profile->update(['next_focus' => $this->nextInRotation($autonomousFocus, $profile->split_type)]);

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
     *
     * Hito R1/R2/R3 — filtrado a `phase===Main`: un warmup/cooldown con
     * `muscle_group` real (ej. "shoulders") NUNCA debe contaminar la
     * detección de foco usada para la rotación de continuidad
     * (`mostNeglectedFocus()`/`decideFocus()`) — solo el bloque principal
     * define "qué se entrenó" para efectos de rotación.
     */
    private function focusOf(WorkoutSession $session): ?string
    {
        $muscleGroups = $session->workoutExercises
            ->where('phase', WorkoutExercisePhase::Main)
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
     * 4. Variedad (Hito Exercise Variety & Selection, MVP — reemplaza la
     *    anti-repetición binaria anterior): SOLO desempata entre ejercicios
     *    ya empatados en foco y nivel — nunca puede hacer que un ejercicio
     *    de un nivel de foco inferior, o con peor ajuste de nivel, le gane
     *    a uno mejor por tener menos exposición reciente. Se mide con
     *    `varietyScore()`, un score continuo de exposición reciente sobre
     *    `$recentSessions` (ver ese método) — nunca un corte binario. Al
     *    ordenar primero por nivel de foco y luego por ajuste de nivel, la
     *    variedad nunca alcanza a comparar dos ejercicios que no eran ya
     *    intercambiables en esas dos dimensiones — misma salvaguarda de
     *    siempre, ahora con un criterio continuo en vez de booleano.
     * 5. Determinismo: último desempate por id ascendente — la misma
     *    combinación de perfil/catálogo/historial produce siempre la misma
     *    sesión.
     *
     * La selección final es, simplemente, tomar los primeros N de [nivel
     * primario ordenado, nivel secundario ordenado, nivel general ordenado]
     * concatenados en ese orden — el orden de los niveles YA garantiza que
     * el foco domina sobre todo lo demás, sin necesitar pesos numéricos
     * calibrados a mano. N ya NO es una constante fija — se calcula
     * dinámicamente por `exercisesForTargetDuration()` a partir de
     * `Tenant.target_session_duration_minutes` (ver esa clase para la
     * fórmula completa).
     *
     * Garantía de foco (Hito 8.4, punto 5 aprobado; generalizada junto con
     * la duración objetivo): con primary_focus declarado, se espera que al
     * menos ceil(N/2) de los ejercicios elegidos vengan de los niveles
     * primario+secundario — la misma fórmula de siempre, ahora sobre N
     * dinámico. Si el catálogo elegible no alcanza para cumplirla, se
     * registra TRAINING_FOCUS_FALLBACK — nunca se bloquea ni se inventa un
     * ejercicio para forzarla (ver docs/DECISIONS.md D034: catálogo real de
     * producción hoy tiene un único ejercicio activo, sin esta metadata).
     * Si el catálogo elegible entrega MENOS ejercicios de los N pedidos por
     * duración (`Collection::take()` nunca inventa, solo entrega lo que
     * hay), se registra TRAINING_DURATION_TARGET_UNREACHABLE — la sesión
     * resultante simplemente tiene menos ejercicios, nunca ejercicios
     * inventados ni relajación de elegibilidad.
     *
     * Hito R1/R2/R3 — recibe `$eligiblePool` (ya filtrado por `isEligible()`)
     * y `$targetMinutes` en vez de calcularlos internamente: evita repetir
     * la misma consulta del catálogo tres veces (una por fase) y permite
     * que el presupuesto de tiempo de R1 se calcule una sola vez en
     * `decideNextSession()`, considerando el reparto con Preparación/
     * Cooldown. Cero cambio de comportamiento respecto a la selección
     * anterior — mismo contenido, misma fuente, solo se evita recalcularla.
     *
     * @return Collection<int, Exercise>
     */
    private function selectExercises(TrainingProfile $profile, string $focus, Collection $recentSessions, Contact $contact, Collection $eligiblePool, float $targetMinutes): Collection
    {
        $eligible = $eligiblePool;

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

        $primaryTier = $this->sortCandidates($primaryTier, $profile, $recentSessions);
        $secondaryTier = $this->sortCandidates($secondaryTier, $profile, $recentSessions);
        $generalTier = $this->sortCandidates($generalTier, $profile, $recentSessions);

        $exercisesPerSession = $this->exercisesForTargetDuration($profile, $contact->tenant, $targetMinutes);

        $focusSlotsAvailable = $primaryTier->count() + $secondaryTier->count();
        $minFocusSlots = (int) ceil($exercisesPerSession / 2);

        if ($primaryFocus !== [] && $focusSlotsAvailable < $minFocusSlots) {
            Log::info('TRAINING_FOCUS_FALLBACK', [
                'contact_id' => $contact->id,
                'primary_focus' => $primaryFocus,
                'secondary_focus' => $secondaryFocus,
                'focus_candidates_available' => $focusSlotsAvailable,
                'min_focus_slots_required' => $minFocusSlots,
            ]);
        }

        $selected = $primaryTier->concat($secondaryTier)->concat($generalTier)
            ->take($exercisesPerSession)
            ->values();

        // Catálogo insuficiente (ver docblock arriba): Collection::take()
        // nunca inventa — si el catálogo elegible entrega menos de lo
        // pedido por duración objetivo, la sesión simplemente queda más
        // corta. Solo se deja constancia operativa, mismo criterio que
        // TRAINING_FOCUS_FALLBACK arriba.
        if ($selected->count() < $exercisesPerSession) {
            Log::info('TRAINING_DURATION_TARGET_UNREACHABLE', [
                'contact_id' => $contact->id,
                'tenant_id' => $contact->tenant->id,
                'target_session_duration_minutes' => $contact->tenant->target_session_duration_minutes,
                'exercises_requested' => $exercisesPerSession,
                'exercises_selected' => $selected->count(),
            ]);
        }

        return $selected;
    }

    /**
     * Hito B1 (Requested Focus) — equivalente de `selectExercises()` cuando
     * el usuario pide una sesión puntual (ej. "pecho y piernas"), usado
     * SOLO cuando `$requestedFocus !== null` (diseño aprobado, Secciones
     * 6-13). NUNCA colapsa los grupos en un único `primaryTier` plano — cada
     * `RequestedFocusGroup` es una unidad de intención independiente,
     * combinada con semántica AND (cada grupo intenta tener representación
     * propia), mientras que los `MuscleFocus` DENTRO de un mismo grupo (ej.
     * "piernas" = quads∪hamstrings∪glutes∪calves) siguen siendo OR (ver
     * `RequestedFocusGroup`).
     *
     * Algoritmo (política ya cerrada, no una propuesta):
     * 1. Candidatos relevantes por grupo, sobre `$eligiblePool` (Safety/
     *    Equipment ya aplicados, sin excepción) — nunca se relaja
     *    `isEligible()` por tratarse de una petición explícita.
     * 2. Slots TEÓRICOS por grupo: `floor(N/G)` + resto repartido a los
     *    primeros grupos por `key` ASC; si `G > N`, 1 slot a los primeros N
     *    grupos por `key` ASC, el resto en 0 (`session_too_short`). El orden
     *    alfabético es SOLO un desempate determinista — nunca representa
     *    prioridad del usuario ni de entrenamiento (diseño aprobado, Sección
     *    8).
     * 3. Slots EFECTIVOS: `min(teórico, candidatos disponibles del grupo)`
     *    — nunca se reserva más de lo que el catálogo elegible realmente
     *    tiene; el déficit pasa al pool libre (paso 5).
     * 4. Reclamo de ejercicios FÍSICAMENTE DISTINTOS: se procesan los grupos
     *    en `key` ASC, cada uno reclama de su propio tier (ya ordenado por
     *    `sortCandidates()`, sin cambios) los candidatos que un grupo
     *    anterior no haya reclamado ya. Un ejercicio relevante para 2 grupos
     *    nunca ocupa 2 slots reservados — una vez reclamado por un grupo,
     *    queda retirado del pool para los siguientes.
     * 5. Pool libre: primero candidatos sobrantes de los propios grupos
     *    solicitados (unión, sin duplicados, ordenados por
     *    `sortCandidates()`), después el `generalTier` autónomo
     *    (`$autonomousFocus`, el mismo mecanismo de rotación de siempre) —
     *    nunca aleatoriedad, mismos criterios de siempre
     *    (difficultyMatchRank → varietyScore → id).
     * 6. Cobertura final: `requestedFocusCoverage()` — calculada DESPUÉS de
     *    cerrar la selección, sobre la lista completa. Un ejercicio puede
     *    contar para la cobertura de varios grupos (conteo generoso,
     *    informativo) sin que eso relaje la reserva por slots del paso 4.
     *
     * @param  array<int, RequestedFocusGroup>  $requestedFocus  Orden
     *                                                           original preservado — usado para la salida (trazabilidad),
     *                                                           nunca para decidir prioridad de slots (eso es `key` ASC).
     * @return array{0: Collection<int, Exercise>, 1: array<int, array{key: string, slots_reserved: int, slots_filled: int, coverage: int, status: string, reason: ?string}>}
     */
    private function selectExercisesForRequestedFocus(
        TrainingProfile $profile,
        array $requestedFocus,
        string $autonomousFocus,
        Collection $recentSessions,
        Contact $contact,
        Collection $eligiblePool,
        Collection $safetyEligiblePool,
        Collection $activePool,
        float $targetMinutes,
    ): array {
        $exercisesPerSession = $this->exercisesForTargetDuration($profile, $contact->tenant, $targetMinutes);
        $groupCount = count($requestedFocus);

        // Desempate determinista SOLO por key — nunca por orden de mención
        // (diseño aprobado, Sección 2/8).
        $orderedByKey = collect($requestedFocus)->sortBy(fn (RequestedFocusGroup $group) => $group->key)->values();

        // Paso 1: candidatos relevantes por grupo, ya ordenados. `$eligiblePool`
        // aquí YA viene reducido por Preference (Hito B3) — la reserva de
        // slots real nunca considera un candidato que el usuario declaró no
        // querer. `$safetyEligiblePool` (Hito B3, anterior a Preference) se
        // usa EXCLUSIVAMENTE para el diagnóstico de 3 vías más abajo, nunca
        // para reservar slots.
        $candidatesByKey = [];
        $safetyCandidatesByKey = [];
        foreach ($requestedFocus as $group) {
            $relevant = $eligiblePool->filter(
                fn (Exercise $exercise) => array_intersect($this->exerciseMuscles($exercise), $group->muscles) !== []
            );
            $candidatesByKey[$group->key] = $this->sortCandidates($relevant, $profile, $recentSessions);

            $safetyCandidatesByKey[$group->key] = $safetyEligiblePool->filter(
                fn (Exercise $exercise) => array_intersect($this->exerciseMuscles($exercise), $group->muscles) !== []
            );
        }

        // Paso 2: slots teóricos.
        $baseSlots = intdiv($exercisesPerSession, $groupCount);
        $remainder = $exercisesPerSession % $groupCount;
        $theoreticalSlots = [];

        foreach ($orderedByKey as $index => $group) {
            if ($baseSlots >= 1) {
                $theoreticalSlots[$group->key] = $baseSlots + ($index < $remainder ? 1 : 0);
            } else {
                // G > N: 1 slot a los primeros N grupos por key ASC, el
                // resto queda en 0 (session_too_short, nunca un slot
                // ficticio).
                $theoreticalSlots[$group->key] = $index < $exercisesPerSession ? 1 : 0;
            }
        }

        // Paso 3: slots efectivos, nunca por encima de la disponibilidad real.
        $effectiveSlots = [];
        foreach ($requestedFocus as $group) {
            $effectiveSlots[$group->key] = min($theoreticalSlots[$group->key], $candidatesByKey[$group->key]->count());
        }

        // Paso 4: reclamo de ejercicios físicamente distintos, en key ASC.
        $claimedIds = [];
        $slotsFilled = [];
        $reservedByKey = [];

        foreach ($orderedByKey as $group) {
            $available = $candidatesByKey[$group->key]->reject(
                fn (Exercise $exercise) => in_array($exercise->id, $claimedIds, true)
            );
            $claimed = $available->take($effectiveSlots[$group->key])->values();

            $reservedByKey[$group->key] = $claimed;
            $slotsFilled[$group->key] = $claimed->count();
            $claimedIds = array_merge($claimedIds, $claimed->pluck('id')->all());
        }

        // Selección reservada, en el orden ORIGINAL de $requestedFocus
        // (trazabilidad) — el orden de reclamo (key ASC) ya cumplió su
        // único propósito (determinismo del reparto), no necesita
        // propagarse a la selección final.
        $reservedSelection = collect();
        foreach ($requestedFocus as $group) {
            $reservedSelection = $reservedSelection->concat($reservedByKey[$group->key]);
        }

        // Paso 5: pool libre.
        $remainingBudget = $exercisesPerSession - $reservedSelection->count();
        $freeSelection = collect();

        if ($remainingBudget > 0) {
            $leftoverFromGroups = collect();
            foreach ($requestedFocus as $group) {
                $leftoverFromGroups = $leftoverFromGroups->concat($candidatesByKey[$group->key]);
            }
            $leftoverFromGroups = $leftoverFromGroups->unique('id')
                ->reject(fn (Exercise $exercise) => in_array($exercise->id, $claimedIds, true));
            $leftoverFromGroups = $this->sortCandidates($leftoverFromGroups, $profile, $recentSessions);

            $fromGroups = $leftoverFromGroups->take($remainingBudget)->values();
            $freeSelection = $freeSelection->concat($fromGroups);
            $claimedIds = array_merge($claimedIds, $fromGroups->pluck('id')->all());
            $remainingBudget -= $fromGroups->count();
        }

        if ($remainingBudget > 0) {
            $generalMuscleGroups = explode(',', $autonomousFocus);
            $generalCandidates = $eligiblePool
                ->filter(fn (Exercise $exercise) => in_array($exercise->muscle_group, $generalMuscleGroups, true))
                ->reject(fn (Exercise $exercise) => in_array($exercise->id, $claimedIds, true));
            $generalCandidates = $this->sortCandidates($generalCandidates, $profile, $recentSessions);

            $freeSelection = $freeSelection->concat($generalCandidates->take($remainingBudget)->values());
        }

        $selected = $reservedSelection->concat($freeSelection)->take($exercisesPerSession)->values();

        // Mismo criterio que selectExercises(): Collection::take() nunca
        // inventa — catálogo insuficiente en TOTAL (ortogonal a la
        // cobertura por grupo) simplemente entrega una sesión más corta.
        if ($selected->count() < $exercisesPerSession) {
            Log::info('TRAINING_DURATION_TARGET_UNREACHABLE', [
                'contact_id' => $contact->id,
                'tenant_id' => $contact->tenant->id,
                'target_session_duration_minutes' => $contact->tenant->target_session_duration_minutes,
                'exercises_requested' => $exercisesPerSession,
                'exercises_selected' => $selected->count(),
            ]);
        }

        $coverage = $this->requestedFocusCoverage(
            $requestedFocus, $theoreticalSlots, $effectiveSlots, $slotsFilled, $candidatesByKey, $safetyCandidatesByKey, $selected, $activePool, $contact,
        );

        return [$selected, $coverage];
    }

    /**
     * Hito B1 — calcula el estado final de cada grupo solicitado, DESPUÉS
     * de que la selección ya está cerrada (diseño aprobado, Secciones 4/12/
     * 13). `coverage` es un conteo GENEROSO (un ejercicio puede contar para
     * varios grupos) — nunca modifica `slots_reserved`/`slots_filled`, que
     * ya quedaron fijados por la reserva física de `selectExercisesForRequestedFocus()`.
     *
     * Estados: `fulfilled` (el grupo alcanzó su reserva teórica),
     * `partial` (alcanzó algo pero menos de lo teórico), `unavailable`
     * (nada). Razones (solo cuando no es `fulfilled`): `session_too_short`
     * (el grupo nunca tuvo slot teórico porque G > N — ver paso 2),
     * `catalog` (el catálogo activo no tiene, o no tiene suficientes,
     * candidatos para el grupo) y `safety_or_equipment` (el catálogo SÍ
     * tiene más candidatos de los que sobrevivieron a `isEligible()` para
     * este grupo). Esta distinción es puramente DIAGNÓSTICA — nunca
     * participa en la selección real, que ya cerró usando únicamente
     * `$eligiblePool`.
     *
     * @param  array<int, RequestedFocusGroup>  $requestedFocus
     * @param  array<string, int>  $theoreticalSlots
     * @param  array<string, int>  $effectiveSlots
     * @param  array<string, int>  $slotsFilled
     * @param  array<string, Collection<int, Exercise>>  $candidatesByKey  Candidatos
     *                                                                     elegibles POR GRUPO (independiente de qué otro grupo haya
     *                                                                     reclamado — la cuenta "cruda" de disponibilidad, usada para el
     *                                                                     diagnóstico catalog/safety_or_equipment; NUNCA `$slotsFilled`,
     *                                                                     que puede ser menor por una colisión de reclamo entre grupos,
     *                                                                     algo que no tiene nada que ver con catálogo ni con Safety).
     * @param  Collection<int, Exercise>  $selected
     * @return array<int, array{key: string, slots_reserved: int, slots_filled: int, coverage: int, status: string, reason: ?string}>
     */
    private function requestedFocusCoverage(
        array $requestedFocus,
        array $theoreticalSlots,
        array $effectiveSlots,
        array $slotsFilled,
        array $candidatesByKey,
        array $safetyCandidatesByKey,
        Collection $selected,
        Collection $activePool,
        Contact $contact,
    ): array {
        $coverage = [];

        foreach ($requestedFocus as $group) {
            $theoretical = $theoreticalSlots[$group->key];
            $reserved = $effectiveSlots[$group->key];
            $filled = $slotsFilled[$group->key];
            $eligibleCount = $candidatesByKey[$group->key]->count();
            $safetyEligibleCount = $safetyCandidatesByKey[$group->key]->count();

            $finalCoverage = $selected->filter(
                fn (Exercise $exercise) => array_intersect($this->exerciseMuscles($exercise), $group->muscles) !== []
            )->count();

            if ($theoretical === 0) {
                $status = 'unavailable';
                $reason = 'session_too_short';
            } elseif ($filled === 0) {
                $status = 'unavailable';
                $reason = $this->requestedFocusUnavailableReason($group, $eligibleCount, $safetyEligibleCount, $activePool);

                Log::info('TRAINING_REQUESTED_FOCUS_UNAVAILABLE', [
                    'contact_id' => $contact->id,
                    'group' => $group->key,
                    'reason' => $reason,
                ]);
            } elseif ($filled < $theoretical) {
                $status = 'partial';
                $reason = $this->requestedFocusUnavailableReason($group, $eligibleCount, $safetyEligibleCount, $activePool);

                Log::info('TRAINING_REQUESTED_FOCUS_PARTIAL', [
                    'contact_id' => $contact->id,
                    'group' => $group->key,
                    'slots_reserved' => $reserved,
                    'slots_filled' => $filled,
                    'reason' => $reason,
                ]);
            } else {
                $status = 'fulfilled';
                $reason = null;
            }

            $coverage[] = [
                'key' => $group->key,
                'slots_reserved' => $reserved,
                'slots_filled' => $filled,
                'coverage' => $finalCoverage,
                'status' => $status,
                'reason' => $reason,
            ];
        }

        return $coverage;
    }

    /**
     * Hito B1 — diagnóstico `catalog` vs. `safety_or_equipment` (diseño
     * aprobado, Sección 13). Hito B3 (diseño v3 FINAL, Sección 5/13) —
     * extendido a 3 vías con un nuevo valor `preference`: compara catálogo
     * ACTIVO -> elegible por Safety+Equipment (`$safetyEligibleCount`) ->
     * elegible tras Preference (`$eligibleCount`, el que realmente participó
     * en la reserva de slots). Se reporta la PRIMERA capa que redujo el
     * conteo (si Safety/Equipment ya redujo algo, se reporta
     * `safety_or_equipment` aunque Preference también haya contribuido —
     * simplificación deliberada, mismo criterio que el diagnóstico de 2 vías
     * anterior). Puramente diagnóstico — nunca cambia qué se selecciona.
     */
    private function requestedFocusUnavailableReason(RequestedFocusGroup $group, int $eligibleCount, int $safetyEligibleCount, Collection $activePool): string
    {
        $catalogCount = $activePool->filter(
            fn (Exercise $exercise) => array_intersect($this->exerciseMuscles($exercise), $group->muscles) !== []
        )->count();

        if ($catalogCount > $safetyEligibleCount) {
            return 'safety_or_equipment';
        }

        if ($safetyEligibleCount > $eligibleCount) {
            return 'preference';
        }

        return 'catalog';
    }

    /**
     * Extraído de la lógica duplicada en `selectExercises()`/`focusScore()`
     * (sin modificarlas) — nuevo helper usado únicamente por el código del
     * Hito B1, para no repetir por tercera vez la misma construcción de
     * `[primary_muscle, ...secondary_muscles]`.
     *
     * @return array<int, string>
     */
    private function exerciseMuscles(Exercise $exercise): array
    {
        return array_values(array_filter(array_merge(
            [$exercise->primary_muscle?->value],
            $exercise->secondary_muscles ?? []
        )));
    }

    /**
     * Traduce `Tenant.target_session_duration_minutes` (duración objetivo
     * APROXIMADA — nunca un máximo estricto ni una promesa exacta, ver
     * migración de la columna) a una cantidad de ejercicios, usando
     * `DurationEstimator` como herramienta de cálculo puro — este método
     * sigue siendo quien DECIDE la cantidad (autoridad de prescripción);
     * `DurationEstimator` nunca decide, solo estima segundos.
     *
     * Usa `GOAL_DEFAULTS[goal]` (sets/rest_seconds) porque en este punto
     * los ejercicios reales todavía no están seleccionados — es una
     * estimación "hacia adelante", sobre la prescripción TÍPICA del goal
     * del perfil, no sobre datos ya persistidos (eso es lo que hace
     * `DurationEstimator::estimateSessionSeconds()` después, sobre la
     * sesión ya creada, para la introducción — ver SessionIntroComposer).
     *
     * MIN/MAX_EXERCISES_PER_SESSION son un guard rail técnico, no una regla
     * de producto — protegen contra una configuración de Tenant fuera de
     * rango llegando por cualquier vía que no sea el formulario de Filament
     * (que ya valida 15-90 minutos en la UI).
     *
     * Hito R1/R2/R3 — `$targetMinutesOverride`: cuando se provee (desde
     * `planSupportBudget()`), sustituye a `Tenant.target_session_duration_minutes`
     * como fuente de los minutos objetivo — la FÓRMULA interna no cambia en
     * absoluto, solo de dónde viene el número de minutos que recibe. `null`
     * (default) preserva exactamente el comportamiento anterior a este
     * hito para cualquier llamador que no lo provea.
     */
    private function exercisesForTargetDuration(TrainingProfile $profile, Tenant $tenant, ?float $targetMinutesOverride = null): int
    {
        $goalDefaults = self::GOAL_DEFAULTS[$profile->goal?->value] ?? self::GOAL_DEFAULTS['general_fitness'];

        $estimatedSecondsPerExercise = $this->durationEstimator->estimateExerciseSeconds(
            $goalDefaults['sets'], $goalDefaults['rest_seconds'], null,
        );

        $targetMinutes = $targetMinutesOverride ?? $tenant->target_session_duration_minutes;
        $exercises = (int) round(($targetMinutes * 60) / $estimatedSecondsPerExercise);

        return max(self::MIN_EXERCISES_PER_SESSION, min(self::MAX_EXERCISES_PER_SESSION, $exercises));
    }

    /**
     * Hito R1/R2/R3 — reparto del presupuesto de tiempo total entre las 3
     * fases, con garantía ALGEBRAICA (no solo descriptiva) de que R1 nunca
     * recibe menos de `MIN_MAIN_BUDGET_RATIO` (70%): `$plannedSupportSeconds`
     * nunca excede `$maxSupportBudgetSeconds` (el techo del 30%), así que
     * `$mainBudgetMinutes = $totalSeconds - $plannedSupportSeconds` nunca
     * baja del piso, por construcción.
     *
     * Los conteos OBJETIVO (`$targetPrepCount`/`$targetCooldownCount`) usan
     * el extremo superior de las franjas ya aprobadas (15min→1/1,
     * 30min→1/1, 45min→1/2, 60min→2/2) — el reparto REAL puede quedar por
     * debajo si el catálogo no tiene suficientes candidatos (best-effort,
     * ver `decideNextSession()`), pero el presupuesto de TIEMPO de R1 se
     * calcula con el objetivo, nunca con la disponibilidad real — mismo
     * principio ya usado por `exercisesForTargetDuration()` para R1 mismo
     * (estima hacia adelante, antes de que los ejercicios reales existan).
     *
     * `$maxSupportSlots` es el techo de cuántos ejercicios de apoyo caben
     * en el 30% — se usa para acotar las CANTIDADES solicitadas a
     * `selectPreparationExercises()`/`selectCooldownExercises()`, no solo
     * para el cálculo de tiempo.
     *
     * @return array{0: int, 1: int, 2: int, 3: float} [targetPrepCount, targetCooldownCount, maxSupportSlots, mainBudgetMinutes]
     */
    private function planSupportBudget(Tenant $tenant): array
    {
        $minutes = (float) $tenant->target_session_duration_minutes;
        $totalSeconds = $minutes * 60;
        $maxSupportBudgetSeconds = $totalSeconds * (1 - self::MIN_MAIN_BUDGET_RATIO);

        $targetPrepCount = $minutes >= 60 ? 2 : 1;
        $targetCooldownCount = $minutes >= 45 ? 2 : 1;

        $maxSupportSlots = (int) floor($maxSupportBudgetSeconds / self::SUPPORT_EXERCISE_DURATION_SECONDS);

        $targetSupportSeconds = ($targetPrepCount + $targetCooldownCount) * self::SUPPORT_EXERCISE_DURATION_SECONDS;
        $plannedSupportSeconds = min($targetSupportSeconds, $maxSupportBudgetSeconds);
        $mainBudgetMinutes = ($totalSeconds - $plannedSupportSeconds) / 60;

        return [$targetPrepCount, $targetCooldownCount, $maxSupportSlots, $mainBudgetMinutes];
    }

    /**
     * Hito R1/R2/R3 — compartido por selectPreparationExercises()/
     * selectCooldownExercises(): misma elegibilidad (ya aplicada en
     * `$eligiblePool`, recibida ya excluyendo los ids usados por otras
     * fases de esta misma sesión — evita que el mismo Exercise se
     * seleccione dos veces), distinto vocabulario permitido
     * (`$allowedExerciseTypes`) y distinta ventana de variedad según fase
     * (ver `sortCandidates()`).
     *
     * El foco es aquí una preferencia DÉBIL — TODO candidato elegible +
     * tipo-válido entra al resultado posible, nunca se excluye por no
     * matchear `primary_focus`/`secondary_focus` (a diferencia de
     * `selectExercises()`, R1, donde el foco SÍ es un filtro real). Ver
     * `sortCandidates()`/`focusScore()`.
     *
     * @return Collection<int, Exercise>
     */
    private function selectSupportExercises(
        TrainingProfile $profile,
        Collection $recentSessions,
        int $count,
        Collection $eligiblePool,
        array $allowedExerciseTypes,
        WorkoutExercisePhase $phase,
    ): Collection {
        if ($count <= 0) {
            return collect();
        }

        $candidates = $eligiblePool->filter(
            fn (Exercise $exercise) => array_intersect($exercise->exercise_type ?? [], $allowedExerciseTypes) !== []
        );

        return $this->sortCandidates($candidates, $profile, $recentSessions, $phase)
            ->take($count)
            ->values();
    }

    private function selectPreparationExercises(TrainingProfile $profile, Collection $recentSessions, int $count, Collection $eligiblePool): Collection
    {
        return $this->selectSupportExercises($profile, $recentSessions, $count, $eligiblePool, self::PREPARATION_TYPES, WorkoutExercisePhase::Preparation);
    }

    private function selectCooldownExercises(TrainingProfile $profile, Collection $recentSessions, int $count, Collection $eligiblePool): Collection
    {
        return $this->selectSupportExercises($profile, $recentSessions, $count, $eligiblePool, self::COOLDOWN_TYPES, WorkoutExercisePhase::Cooldown);
    }

    /**
     * Hito R1/R2/R3 — `$phase` (default `Main`, preserva exactamente el
     * comportamiento anterior a este hito para R1, que nunca pasa este
     * parámetro explícitamente):
     * - `Main`: comparador SIN CAMBIOS — difficultyMatchRank() →
     *   varietyScore() → id.
     * - `Preparation`/`Cooldown`: comparador nuevo — varietyScore() →
     *   focusScore() → id. El foco NUNCA es el primer criterio (a
     *   diferencia del filtrado por tiers de R1) — es una preferencia
     *   DÉBIL que solo desempata cuando la variedad ya está empatada; la
     *   variedad domina siempre que exista una diferencia real. Sin
     *   `difficultyMatchRank()` — no es un criterio pedido para estas
     *   fases (`difficulty` no es obligatorio en R2/R3).
     *
     * @return Collection<int, Exercise>
     */
    private function sortCandidates(Collection $candidates, TrainingProfile $profile, Collection $recentSessions, WorkoutExercisePhase $phase = WorkoutExercisePhase::Main): Collection
    {
        $decay = match ($phase) {
            WorkoutExercisePhase::Preparation => self::PREPARATION_VARIETY_DECAY,
            WorkoutExercisePhase::Cooldown => self::COOLDOWN_VARIETY_DECAY,
            WorkoutExercisePhase::Main => self::VARIETY_DECAY,
        };

        // Score de variedad calculado UNA vez por candidato (no en cada
        // comparación de sort()) — mismo resultado, menos trabajo repetido.
        $varietyScores = $candidates->mapWithKeys(
            fn (Exercise $exercise) => [$exercise->id => $this->varietyScore($exercise->id, $recentSessions, $decay)]
        );

        if ($phase === WorkoutExercisePhase::Main) {
            return $candidates->sort(function (Exercise $a, Exercise $b) use ($profile, $varietyScores) {
                $levelDiff = $this->difficultyMatchRank($a, $profile) <=> $this->difficultyMatchRank($b, $profile);
                if ($levelDiff !== 0) {
                    return $levelDiff;
                }

                $varietyDiff = $varietyScores[$a->id] <=> $varietyScores[$b->id];
                if ($varietyDiff !== 0) {
                    return $varietyDiff;
                }

                return $a->id <=> $b->id;
            })->values();
        }

        $focusScores = $candidates->mapWithKeys(
            fn (Exercise $exercise) => [$exercise->id => $this->focusScore($exercise, $profile)]
        );

        return $candidates->sort(function (Exercise $a, Exercise $b) use ($varietyScores, $focusScores) {
            $varietyDiff = $varietyScores[$a->id] <=> $varietyScores[$b->id];
            if ($varietyDiff !== 0) {
                return $varietyDiff;
            }

            // Descendente: focusScore=1 (coincide con el foco declarado)
            // antes que focusScore=0 — solo como desempate, nunca antes.
            $focusDiff = $focusScores[$b->id] <=> $focusScores[$a->id];
            if ($focusDiff !== 0) {
                return $focusDiff;
            }

            return $a->id <=> $b->id;
        })->values();
    }

    /**
     * Hito R1/R2/R3 — preferencia DÉBIL de foco para Preparación/Cooldown:
     * `1` si el ejercicio coincide con `primary_focus`∪`secondary_focus`
     * del perfil, `0` si no. Nunca excluye (a diferencia de los tiers de
     * R1) — solo usado como desempate en `sortCandidates()`, después de la
     * variedad.
     */
    private function focusScore(Exercise $exercise, TrainingProfile $profile): int
    {
        $muscles = array_values(array_filter(array_merge(
            [$exercise->primary_muscle?->value],
            $exercise->secondary_muscles ?? []
        )));

        $declaredFocus = array_merge($profile->primary_focus ?? [], $profile->secondary_focus ?? []);

        return array_intersect($muscles, $declaredFocus) !== [] ? 1 : 0;
    }

    /**
     * Hito — Exercise Variety & Selection (MVP). Reemplaza la señal binaria
     * anterior (`ANTI_REPETITION_LOOKBACK_SESSIONS`/`recentlyUsedExerciseIds`)
     * por un score CONTINUO de exposición reciente:
     *
     *     varietyScore = Σ VARIETY_DECAY ^ posición
     *
     * calculado EXCLUSIVAMENTE sobre `$recentSessions` — el mismo historial
     * ligero que `selectExercises()` ya recibía como parámetro, sin ninguna
     * consulta nueva, sin `TrainingHistoryContextProvider`, sin
     * `ProgressionEvaluator`, sin `exerciseLog`/`exerciseSets`. `posición`
     * es el índice dentro de esa colección (0 = sesión más reciente, hasta
     * 4 = la 5ta, límite dado por `RECENT_SESSIONS_LOOKBACK`, sin cambios)
     * — ya viene ordenada por `scheduled_at` DESC por la propia query de
     * `decideNextSession()`, nunca se reordena aquí. Un ejercicio ausente
     * de esas <=5 sesiones obtiene `0.0`, indistinguible de uno nunca
     * utilizado — limitación conocida del MVP, no un error (la "memoria"
     * de variedad no puede ser mayor que la profundidad de `$recentSessions`).
     *
     * Cada sesión aporta COMO MÁXIMO un término, sin importar cuántas filas
     * `WorkoutExercise` con ese mismo `exercise_id` existan dentro de ella
     * — de ahí el `->unique()`: el score representa exposición POR SESIÓN,
     * nunca cantidad de filas.
     *
     * `scheduled_at`, no `completed_at`: `$recentSessions` ya ordena por
     * `scheduled_at` (semántica de secuencia de decisiones de
     * `TrainingEngine`, igual que `mostNeglectedFocus()`) — sin cambios,
     * ver docs/DECISIONS.md de este hito para el análisis de equivalencia
     * de orden entre ambos campos.
     *
     * Determinista: función pura de `$recentSessions` (ya ordenada por la
     * query) y `VARIETY_DECAY` (constante fija) — misma entrada, mismo
     * resultado, siempre. Solo desempata DENTRO de `sortCandidates()`,
     * después de `difficultyMatchRank` — nunca puede superar esa prioridad
     * ni el tier de foco (ver docblock de `selectExercises()`).
     */
    private function varietyScore(int $exerciseId, Collection $recentSessions, float $decay = self::VARIETY_DECAY): float
    {
        $score = 0.0;

        foreach ($recentSessions->values() as $position => $session) {
            $exerciseIdsInSession = $session->workoutExercises->pluck('exercise_id')->unique();

            if ($exerciseIdsInSession->contains($exerciseId)) {
                $score += $decay ** $position;
            }
        }

        // Redondeo de higiene numérica (no de negocio): la suma de potencias
        // de un decimal en coma flotante puede producir ruido de
        // representación (ej. 1.3599999999999999 en vez de 1.36) — se
        // redondea a 6 decimales, muy por debajo de cualquier diferencia
        // significativa entre candidatos, nunca afecta el desempate real.
        return round($score, 6);
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

        $equipmentNeeded = $exercise->equipment_needed ?? [];

        // Hito Provider-Agnostic Normalization — Equipment::Unsupported
        // significa "un proveedor declaró un requisito de equipamiento que
        // el dominio todavía no puede representar", NUNCA "sin equipo" ni
        // "equipo real que un usuario con todo razonablemente tendría". Se
        // evalúa ANTES que cualquier otro camino de elegibilidad —
        // deliberadamente por delante de equipment_fully_equipped, que de
        // otro modo (era un OR que nunca inspecciona el contenido del
        // array) lo trataría como elegible sin más. Ningún ejercicio
        // Active/pending_review actual tiene este valor (Audit #3) — esta
        // rama es 100% aditiva, no cambia el comportamiento de nada
        // existente, solo define correctamente el vocabulario nuevo.
        if (in_array(Equipment::Unsupported->value, $equipmentNeeded, true)) {
            return false;
        }

        // Hito 9.0: training_location no tenía ningún consumidor real —
        // "outdoor" es la única ubicación con una consecuencia dura y
        // honesta de modelar: lo que el usuario POSEE (available_equipment/
        // equipment_fully_equipped) no es lo mismo que lo que tiene CONSIGO
        // en un parque. Un ejercicio que exige equipo queda inelegible sin
        // importar esos dos campos.
        if ($profile->training_location === TrainingLocation::Outdoor && $equipmentNeeded !== []) {
            return false;
        }

        if ($equipmentNeeded === [] || $profile->equipment_fully_equipped === true) {
            return true;
        }

        $available = $profile->available_equipment ?? [];

        return array_diff($equipmentNeeded, $available) === [];
    }

    /**
     * Hito B3 (diseño v3 FINAL, Sección E/17) — filtro de Preference,
     * DELIBERADAMENTE separado de `isEligible()` (Regla 13 del encargo de
     * implementación), aplicado inmediatamente después en
     * `decideNextSession()`. `$excluded` ya viene nivelado por
     * `TrainingPreferenceResolver` — este método nunca conoce el modelo
     * `TrainingPreference`.
     */
    private function isExcludedByPreference(Exercise $exercise, array $excluded): bool
    {
        if (in_array($exercise->id, $excluded['exercise_ids'], true)) {
            return true;
        }

        $equipmentNeeded = $exercise->equipment_needed ?? [];

        return array_intersect($equipmentNeeded, $excluded['equipment']) !== [];
    }

    /**
     * Hito B3 (diseño v3 FINAL, Sección G, revisión v3 punto 5) —
     * `applied_preferences` del snapshot. Cálculo INDEPENDIENTE por cada
     * preferencia activa, siempre contra `$eligiblePoolBeforePreference`
     * (el pool posterior a Safety+Equipment, ANTERIOR a cualquier filtro de
     * Preference) — nunca secuencial. Los conteos pueden solaparse
     * intencionalmente (ej. un mismo ejercicio excluido tanto por una
     * preferencia de Exercise como por una de Equipment cuenta en ambas
     * entradas) — la suma de todos los conteos NO representa el total de
     * candidatos únicos excluidos. Solo se incluyen preferencias que
     * realmente excluyeron al menos 1 candidato en ESTA sesión
     * (`excluded_candidates_count > 0`) — una preferencia activa sin ningún
     * candidato que excluir aquí no aparece.
     *
     * @return array<int, array{dimension: string, value: string, exercise_id: ?int, excluded_candidates_count: int}>
     */
    private function computeAppliedPreferences(array $excluded, Collection $eligiblePoolBeforePreference): array
    {
        $applied = [];

        foreach ($excluded['exercise_ids'] as $exerciseId) {
            $matches = $eligiblePoolBeforePreference->filter(fn (Exercise $e) => $e->id === $exerciseId);

            if ($matches->isEmpty()) {
                continue;
            }

            $exercise = $matches->first();
            $applied[] = [
                'dimension' => 'exercise',
                'value' => $exercise->name_es ?? $exercise->name,
                'exercise_id' => $exerciseId,
                'excluded_candidates_count' => $matches->count(),
            ];
        }

        foreach ($excluded['equipment'] as $equipmentValue) {
            $count = $eligiblePoolBeforePreference->filter(
                fn (Exercise $e) => in_array($equipmentValue, $e->equipment_needed ?? [], true)
            )->count();

            if ($count === 0) {
                continue;
            }

            $applied[] = [
                'dimension' => 'equipment',
                'value' => $equipmentValue,
                'exercise_id' => null,
                'excluded_candidates_count' => $count,
            ];
        }

        return $applied;
    }

    private function prescribeExercise(WorkoutSession $session, Exercise $exercise, int $order, TrainingHistoryContext $historyContext, TrainingProfile $profile): WorkoutExercise
    {
        return WorkoutExercise::create(array_merge(
            ['workout_session_id' => $session->id],
            $this->mainExerciseAttributes($exercise, $order, $historyContext, $profile),
        ));
    }

    /**
     * Hito C (Sustitución de un ejercicio) — extraído SIN CAMBIO de
     * comportamiento de `prescribeExercise()` (que ahora delega aquí) para
     * que `selectReplacement()` pueda reutilizar EXACTAMENTE el mismo
     * cálculo de progresión/prescripción sin duplicarlo y sin persistir por
     * su cuenta (`selectReplacement()` nunca llama `WorkoutExercise::create()`
     * — eso es responsabilidad exclusiva de `ReplaceWorkoutExerciseService`).
     * Bloque 8 (D051): ProgressionEvaluator es la única autoridad sobre la
     * DIRECCIÓN de progresión; TrainingEngine sigue siendo la única
     * autoridad sobre la PRESCRIPCIÓN numérica concreta.
     *
     * @return array<string, mixed> atributos listos para `WorkoutExercise::create()`,
     *         SIN `workout_session_id` (lo añade el llamador).
     */
    private function mainExerciseAttributes(Exercise $exercise, int $order, TrainingHistoryContext $historyContext, TrainingProfile $profile): array
    {
        $evaluation = $this->progressionEvaluator->evaluate($historyContext, $exercise->id, $exercise->tracking_type);
        $progression = $this->numericPrescriptionFor($exercise, $evaluation, $historyContext, $profile);

        return [
            'exercise_id' => $exercise->id,
            'order' => $order,
            'phase' => WorkoutExercisePhase::Main,
            'prescribed_sets' => $progression['sets'],
            'prescribed_reps' => $progression['reps'],
            'prescribed_load' => $progression['load'],
            'prescribed_duration_seconds' => $progression['duration_seconds'],
            'rest_seconds' => $progression['rest_seconds'],
            'exercise_snapshot' => $exercise->toSnapshot(),
        ];
    }

    /**
     * Hito R1/R2/R3 — prescripción de Preparación/Cooldown: NUNCA invoca
     * `ProgressionEvaluator`/`numericPrescriptionFor()` (Decisión #6/#7 del
     * diseño aprobado) — mismo `WorkoutExercise::create()` que R1, con
     * valores fijos en vez de progresión histórica. `prescribed_sets=1`/
     * `rest_seconds=0` (nunca `null`): `DurationEstimator::
     * estimateExerciseSeconds(int $sets, int $restSeconds, ?int $duration)`
     * exige ambos como `int` no-nullable — pasar `null` produciría un
     * `TypeError` la primera vez que se estime la duración de la sesión
     * (ej. `SessionIntroComposer`). `1`/`0` representan fielmente "un solo
     * bloque continuo, sin descanso estructurado", sin inventar un segundo
     * motor de prescripción.
     */
    private function prescribeSupportExercise(WorkoutSession $session, Exercise $exercise, int $order, WorkoutExercisePhase $phase): WorkoutExercise
    {
        return WorkoutExercise::create(array_merge(
            ['workout_session_id' => $session->id],
            $this->supportExerciseAttributes($exercise, $order, $phase),
        ));
    }

    /**
     * Hito C — extraído SIN CAMBIO de comportamiento de
     * `prescribeSupportExercise()`, mismo motivo exacto que
     * `mainExerciseAttributes()`.
     *
     * @return array<string, mixed> atributos listos para `WorkoutExercise::create()`,
     *         SIN `workout_session_id` (lo añade el llamador).
     */
    private function supportExerciseAttributes(Exercise $exercise, int $order, WorkoutExercisePhase $phase): array
    {
        return [
            'exercise_id' => $exercise->id,
            'order' => $order,
            'phase' => $phase,
            'prescribed_sets' => 1,
            'prescribed_reps' => null,
            'prescribed_load' => null,
            'prescribed_duration_seconds' => self::SUPPORT_EXERCISE_DURATION_SECONDS,
            'rest_seconds' => 0,
            'exercise_snapshot' => $exercise->toSnapshot(),
        ];
    }

    /**
     * Hito C (Sustitución de un ejercicio, diseño formal aprobado) — ÚNICA
     * operación de selección para sustituir UN `WorkoutExercise` dentro de
     * una sesión que sigue viva. REGLA ABSOLUTA: este método NUNCA persiste
     * — no abre transacción, no hace `lockForUpdate()`, no crea ni modifica
     * ningún `WorkoutExercise`/`WorkoutSession`. Devuelve únicamente los
     * atributos de la prescripción elegida; `ReplaceWorkoutExerciseService`
     * es quien crea la fila real y marca `superseded_by_id` del original,
     * dentro de su propia transacción con lock.
     *
     * Reutiliza, SIN DUPLICAR, exactamente las mismas piezas que
     * `decideNextSession()`: `isEligible()` (Safety/Location/Equipment),
     * `isExcludedByPreference()` (B3), `sortCandidates()`/`varietyScore()`
     * (mismo criterio de fase — Main: dificultad→variedad→id; Preparation/
     * Cooldown: variedad→foco→id), y `mainExerciseAttributes()`/
     * `supportExerciseAttributes()` para la prescripción final.
     *
     * Exclusión (cierra el gap ya documentado en la auditoría previa,
     * Sección K): se excluye TODO `exercise_id` actualmente ACTIVO en la
     * MISMA sesión — `$target->workoutSession->workoutExercises` ya usa la
     * relación filtrada por `superseded_by_id IS NULL` (ver
     * `WorkoutSession::workoutExercises()`), y como `$target` en sí sigue
     * activo en el momento de esta llamada (el Service revalida
     * `superseded_by_id === null` bajo lock ANTES de invocar este método,
     * nunca lo escribe antes), su propio `exercise_id` queda EXCLUIDO de
     * forma estructural, sin necesitar un caso especial — nunca puede
     * proponerse a sí mismo como su propio reemplazo.
     *
     * `$requestedFocus`: UN solo grupo (nunca un array — a diferencia de
     * B1, que reparte slots entre múltiples grupos para una sesión
     * COMPLETA, aquí solo hay 1 slot que llenar). Si el foco pedido no
     * tiene ningún candidato tras Safety/Preference/exclusión de sesión, se
     * hace fallback silencioso-pero-honesto al pool general (mismo
     * principio que B1: nunca bloquea por un foco insatisfacible) — el
     * candidato real elegido queda disponible en el resultado para que el
     * llamador informe honestamente cuál fue.
     *
     * @return array<string, mixed> atributos listos para `WorkoutExercise::create()`,
     *         SIN `workout_session_id` (el Service lo añade al persistir).
     *
     * @throws TrainingCatalogInsufficientException si, tras Safety/Location/
     *         Equipment/Preference/exclusión de sesión (y el fallback de
     *         foco si aplica), no queda ningún candidato — mismo criterio y
     *         MISMA excepción que ya usa `decideNextSession()` para
     *         "catálogo insuficiente", nunca una excepción nueva.
     */
    public function selectReplacement(Contact $contact, WorkoutExercise $target, ?RequestedFocusGroup $requestedFocus = null): array
    {
        $profile = $contact->trainingProfile;

        if ($profile === null) {
            throw new \RuntimeException('Cannot select a replacement without a TrainingProfile.');
        }

        $session = $target->workoutSession;

        $activePool = Exercise::query()->where('is_active', true)->get();
        $eligiblePool = $activePool->filter(fn (Exercise $exercise) => $this->isEligible($exercise, $profile));

        $excludedByPreference = $this->preferenceResolver->excludedIdentifiersFor($contact);
        $preferenceFilteredPool = $eligiblePool->reject(
            fn (Exercise $exercise) => $this->isExcludedByPreference($exercise, $excludedByPreference)
        );

        // Fix post-deploy (auditoría de reutilización intra-sesión, E2E
        // real) — excluye TODO exercise_id que haya aparecido ALGUNA VEZ en
        // esta sesión (Preparation+Main+Cooldown), esté activo o ya
        // `superseded_by_id`. Consulta DIRECTA sobre `workout_session_id`
        // (nunca `$session->workoutExercises`, que `WorkoutSession` filtra
        // por diseño con `whereNull('superseded_by_id')` — correcto para
        // frontExercise()/entrega/progresión, pero INCORRECTO aquí: usarlo
        // dejaba que cada sustitución "liberara" el exercise_id recién
        // superseded para la siguiente sustitución de la misma sesión,
        // permitiendo reintroducir un ejercicio que el usuario ya había
        // descartado). Sigue incluyendo estructuralmente al propio $target
        // (su fila existe con este workout_session_id sin importar si ya
        // está marcada superseded en el momento de esta llamada). Scope
        // estrictamente por $session->id — nunca afecta a otra sesión
        // (pasada o futura): la variedad entre sesiones sigue siendo
        // responsabilidad exclusiva de varietyScore()/$recentSessions, sin
        // cambios.
        $historicalSessionExerciseIds = WorkoutExercise::query()
            ->where('workout_session_id', $session->id)
            ->pluck('exercise_id')
            ->filter()
            ->unique();
        $candidatePool = $preferenceFilteredPool->reject(
            fn (Exercise $exercise) => $historicalSessionExerciseIds->contains($exercise->id)
        );

        $recentSessions = $contact->workoutSessions()
            ->whereIn('status', [
                WorkoutSessionStatus::Completed,
                WorkoutSessionStatus::Skipped,
                WorkoutSessionStatus::Superseded,
            ])
            ->with('workoutExercises')
            ->orderByDesc('scheduled_at')
            ->limit(self::RECENT_SESSIONS_LOOKBACK)
            ->get();

        $pool = $candidatePool;

        if ($requestedFocus !== null) {
            $focusedPool = $candidatePool->filter(
                fn (Exercise $exercise) => array_intersect($this->exerciseMuscles($exercise), $requestedFocus->muscles) !== []
            );

            if ($focusedPool->isNotEmpty()) {
                $pool = $focusedPool;
            }
        }

        $chosen = $this->sortCandidates($pool, $profile, $recentSessions, $target->phase)->first();

        if ($chosen === null) {
            throw new TrainingCatalogInsufficientException;
        }

        if ($target->phase === WorkoutExercisePhase::Main) {
            $historyContext = $this->historyProvider->build($contact);

            return $this->mainExerciseAttributes($chosen, $target->order, $historyContext, $profile);
        }

        return $this->supportExerciseAttributes($chosen, $target->order, $target->phase);
    }

    /**
     * Bloque 8 (D051) — traduce una `ProgressionEvaluation` (dirección,
     * decidida por `ProgressionEvaluator`) en una prescripción numérica
     * concreta. Ningún dato nuevo se consulta a la base de datos: toda la
     * evidencia histórica ya viene en `$historyContext` (construido UNA
     * vez por generación) y en `$evaluation` (evaluada sin SQL propio).
     *
     * - `insufficient_data`: prescripción inicial de `GOAL_DEFAULTS`,
     *   idéntica al comportamiento histórico de "sin ejecución previa" —
     *   nunca se inventa una progresión sobre evidencia insuficiente.
     * - `maintain`: conserva el carry-forward existente — intensidad REAL
     *   de la ejecución más reciente (`evaluation->metrics->lastIntensity`),
     *   con fallback a la prescripción histórica de esa misma ejecución
     *   (Bloque 7, `prescribedLoad`/`prescribedReps`/`prescribedSets`) y
     *   finalmente a `GOAL_DEFAULTS`.
     * - `progress`: mismos incrementos existentes (`+2.5` carga, `+10s`
     *   duración) — lo nuevo es que la DECISIÓN de progresar ya no la toma
     *   este método, la toma `ProgressionEvaluator` con más evidencia.
     * - `reduce`: **sin política numérica propia en v1** (ver D051) — se
     *   traduce exactamente igual que `maintain`, nunca se inventa una
     *   magnitud de reducción. Los `reasonCodes`/métricas de `reduce`
     *   siguen disponibles en `$evaluation` para un futuro Coach o una
     *   futura política numérica, aunque este método no los traduzca a un
     *   número distinto todavía.
     *
     * `rest_seconds` y `sets` nunca tuvieron progresión por historial —
     * sin cambios respecto al comportamiento anterior.
     *
     * @return array{sets: ?int, reps: ?int, load: ?float, duration_seconds: ?int, rest_seconds: int}
     */
    private function numericPrescriptionFor(
        Exercise $exercise,
        ProgressionEvaluation $evaluation,
        TrainingHistoryContext $historyContext,
        TrainingProfile $profile,
    ): array {
        $isTimeBased = $exercise->tracking_type === TrackingType::TimeBased;
        $goalDefaults = self::GOAL_DEFAULTS[$profile->goal?->value] ?? self::GOAL_DEFAULTS['general_fitness'];

        if ($evaluation->decision === ProgressionDecision::InsufficientData) {
            return $isTimeBased
                ? ['sets' => $goalDefaults['sets'], 'reps' => null, 'load' => null, 'duration_seconds' => $goalDefaults['duration_seconds'], 'rest_seconds' => $goalDefaults['rest_seconds']]
                : ['sets' => $goalDefaults['sets'], 'reps' => $goalDefaults['reps'], 'load' => null, 'duration_seconds' => null, 'rest_seconds' => $goalDefaults['rest_seconds']];
        }

        $mostRecent = $this->mostRecentEntryFor($historyContext, $exercise->id);
        $sets = $mostRecent?->prescribedSets ?? $goalDefaults['sets'];

        // `reduce` no tiene política numérica propia en v1 (D051): se
        // traduce igual que `maintain` — nunca se inventa una magnitud de
        // reducción. Solo `progress` incrementa.
        $shouldProgress = $evaluation->decision === ProgressionDecision::Progress;

        if ($isTimeBased) {
            // Bloque 8: corrección aprobada — `lastIntensity` ya representa
            // el MÁXIMO `actual_duration_seconds` entre los sets de la
            // ejecución más reciente (D050), nunca el primer set por
            // accidente de un `sortByDesc('actual_load')` degenerado.
            $lastDuration = $evaluation->metrics->lastIntensity ?? $goalDefaults['duration_seconds'];

            return [
                'sets' => $sets,
                'reps' => null,
                'load' => null,
                'duration_seconds' => $shouldProgress ? $lastDuration + 10 : $lastDuration,
                'rest_seconds' => $goalDefaults['rest_seconds'],
            ];
        }

        $topSet = $this->topSetByLoad($mostRecent);

        $lastLoad = $evaluation->metrics->lastIntensity ?? $mostRecent?->prescribedLoad;
        $lastReps = $topSet?->reps ?? $mostRecent?->prescribedReps ?? $goalDefaults['reps'];

        return [
            'sets' => $sets,
            'reps' => $lastReps,
            'load' => $lastLoad !== null && $shouldProgress ? ($lastLoad + 2.5) : $lastLoad,
            'duration_seconds' => null,
            'rest_seconds' => $goalDefaults['rest_seconds'],
        ];
    }

    /**
     * Bloque 8 (D051) — recupera del `TrainingHistoryContext` YA
     * CONSTRUIDO (sin ninguna consulta nueva) la MISMA ejecución E que
     * `ProgressionEvaluator` usa conceptualmente para decidir la dirección
     * (D050: "la ejecución `Performed` cronológicamente más reciente del
     * ejercicio") — nunca una entrada `Skipped` ni `Unreported`, aunque sea
     * más reciente. Un `Skipped` reciente puede seguir apareciendo como
     * dato informativo (`reasonCode = most_recent_execution_skipped` en la
     * evaluación), pero nunca como fuente de reps/carga/duración/
     * prescripción histórica para el carry-forward numérico — hacerlo
     * mezclaría dos fuentes distintas de "E" entre el evaluador y el
     * motor. Si no existe ninguna ejecución `Performed`, devuelve `null` —
     * `numericPrescriptionFor()` nunca llega a usar este resultado en ese
     * caso porque `$evaluation->decision` ya es `insufficient_data`.
     * Es un simple recorrido de lectura, nunca una segunda autoridad de
     * dirección: `ProgressionEvaluator` sigue siendo quien decide
     * progress/maintain/reduce/insufficient_data; este helper solo
     * recupera los datos crudos necesarios para traducir esa decisión a
     * números.
     */
    private function mostRecentEntryFor(TrainingHistoryContext $historyContext, int $exerciseId): ?HistoryExerciseEntry
    {
        foreach ($historyContext->sessions as $session) {
            foreach ($session->exercises as $exerciseEntry) {
                if ($exerciseEntry->exerciseId === $exerciseId
                    && $exerciseEntry->outcome === HistoryExerciseOutcome::Performed) {
                    return $exerciseEntry;
                }
            }
        }

        return null;
    }

    /**
     * Mismo criterio que el código anterior usaba (`sortByDesc('actual_load')
     * ->first() ?? first()`), replicado sobre `HistorySetEntry` en vez de
     * `ExerciseSet` — el set de mayor carga entre los de esta ejecución, o
     * el primero si ninguno tiene carga.
     */
    private function topSetByLoad(?HistoryExerciseEntry $entry): ?HistorySetEntry
    {
        if ($entry === null || $entry->sets === []) {
            return null;
        }

        return collect($entry->sets)->sortByDesc('load')->first();
    }
}
