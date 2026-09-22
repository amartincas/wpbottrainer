<?php

namespace App\Training\Support;

use App\Models\ExerciseLog;
use App\Models\ExerciseSet;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Events\WorkoutSessionCompleted;
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
    /**
     * @param  ?int  $frontExerciseId  Corrección post-incidente de staging
     *         (#33, hito R1/R2/R3) — el `WorkoutExercise` id que el
     *         CONTEXTO DE EJECUCIÓN (`TrainingHandler`, vía
     *         `WorkoutSession::frontExercise()`) ya determinó como el
     *         frente actual — el MISMO id que recibió `ExecutionReportService`
     *         para construir su prompt (ver `TrainingHandler::recordExecutionReport()`).
     *         Única identidad que `resolveExercise()` usa
     *         como *fallback* cuando la IA no nombra explícitamente un
     *         ejercicio — nunca "el primero de `$unreported`". `null`
     *         cuando el frente no requiere reporte o no hay sesión activa;
     *         en ese caso un reporte sin nombre explícito NUNCA se
     *         atribuye a nada.
     */
    public function record(WorkoutSession $session, array $extraction, ?int $frontExerciseId = null): ExecutionReportOutcome
    {
        $session->load(['workoutExercises.exerciseLog']);

        // Hito R1/R2/R3 — `requiresExecutionReport()` (solo Main) filtra
        // ANTES de mirar `exerciseLog`: sin esto, un Preparation/Cooldown
        // (que nunca tiene `exerciseLog`) podría quedar como "el primero
        // sin resolver" y `resolveExercise()` le resolvería incorrectamente
        // un reporte sin nombre explícito — bug real detectado en el
        // diseño, no solo cosmético. Este pool sigue sirviendo para
        // matching por NOMBRE explícito (corrección retroactiva) — la
        // restricción real está en `resolveExercise()`, ver más abajo.
        $unreported = $session->workoutExercises
            ->filter(fn (WorkoutExercise $we) => $we->requiresExecutionReport() && $we->exerciseLog === null)
            ->values();

        $logged = [];
        $clarifications = [];
        $partialIds = [];

        foreach ($extraction['reports'] as $report) {
            $resolved = $this->resolveExercise($report['exercise_name'], $unreported, $frontExerciseId);

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

            // H16.2 Fase 1.3 (auditoría de flujo conversacional, Caso 1B) —
            // el usuario reportó MENOS series de las prescritas: se persiste
            // exactamente lo dicho (nunca se infla), pero el turno no debe
            // avanzar en silencio al siguiente ejercicio — se pregunta si
            // continuará o lo deja hasta ahí. `$partialIds` hace que ese
            // ejercicio siga contando como "sin resolver" SOLO para las
            // decisiones de este mismo turno (avance/cierre) — ver
            // maybeCompleteSession() y TrainingHandler::recordExecutionReport().
            // Nunca cambia qué significa "Unreported" (exerciseLog === null)
            // para ningún otro consumidor del sistema.
            if ($this->isPartialReport($resolved, $report)) {
                $clarifications[] = $this->partialSetsClarification($resolved, $report);
                $partialIds[] = $resolved->id;

                Log::info('TRAINING_REPORT_PARTIAL_SETS', [
                    'workout_exercise_id' => $resolved->id,
                    'reported_sets' => count($report['sets']),
                    'prescribed_sets' => $resolved->prescribed_sets,
                ]);
            }
        }

        $sessionCompleted = $this->maybeCompleteSession($session, $partialIds);

        return new ExecutionReportOutcome($logged, $clarifications, $sessionCompleted, $partialIds);
    }

    /**
     * H16.2 Fase 1.3 — true únicamente cuando el usuario reportó ejecución
     * real (no `not_performed`), el ejercicio tiene una prescripción de
     * series conocida, y reportó AL MENOS una serie pero MENOS de las
     * prescritas. Nunca dispara para más series que las prescritas (fuera
     * de alcance) ni cuando `prescribed_sets` es null (sin prescripción
     * conocida, nada contra qué comparar).
     */
    private function isPartialReport(WorkoutExercise $we, array $report): bool
    {
        return ! $report['not_performed']
            && $we->prescribed_sets !== null
            && count($report['sets']) > 0
            && count($report['sets']) < $we->prescribed_sets;
    }

    /**
     * Pregunta determinista de seguimiento — nunca decide nada, solo
     * pregunta; el código nunca asume la respuesta ni completa las series
     * faltantes por su cuenta.
     */
    private function partialSetsClarification(WorkoutExercise $we, array $report): string
    {
        $remaining = $we->prescribed_sets - count($report['sets']);
        $word = $remaining === 1 ? 'serie' : 'series';

        return "¿Vas a hacer {$remaining} {$word} más de {$this->nameOf($we)}, o lo dejas hasta ahí por hoy?";
    }

    /**
     * @param Collection<int, WorkoutExercise> $unreported
     */
    private function resolveExercise(?string $name, Collection $unreported, ?int $frontExerciseId): ?WorkoutExercise
    {
        if ($name !== null) {
            // Nombre explícito que no matchea ningún pendiente real (posible
            // alucinación/typo de la IA) — nunca se adivina, cae al mismo
            // clarificationMessage($name, ...) que ya existía.
            return $unreported->first(
                fn (WorkoutExercise $we) => mb_strtolower($we->exercise_snapshot['name'] ?? '') === mb_strtolower($name)
            );
        }

        // Corrección post-incidente de staging (#33, hito R1/R2/R3) — sin
        // nombre explícito, el reporte implícito SOLO puede atribuirse al
        // FRENTE real ya determinado por el contexto de ejecución (mismo
        // id que recibió `ExecutionReportService` para construir su
        // prompt) — NUNCA "el primero de $unreported". Antes de esta
        // corrección, `$unreported->first()` podía seleccionar un Main que
        // el usuario nunca vio entregado (el frente real era un
        // Preparation/Cooldown, excluido de `$unreported` por diseño),
        // atribuyéndole un reporte real a un ejercicio ajeno — el bug
        // exacto reproducido en staging. `$frontExerciseId === null`
        // (frente no requiere reporte, o no hay sesión activa) nunca
        // inventa un ejercicio: cae a la clarificación existente.
        if ($frontExerciseId === null) {
            return null;
        }

        return $unreported->first(fn (WorkoutExercise $we) => $we->id === $frontExerciseId);
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

    /**
     * Hito R1/R2/R3 — pasa a público para que `TrainingHandler` pueda
     * re-invocarlo tras entregar un Cooldown final (que se entrega DESPUÉS
     * de que este método ya corrió una vez dentro de `record()`, en el
     * mismo turno del último reporte de R1 — ver docblock de
     * `TrainingHandler::recordExecutionReport()`). Única rutina de cierre
     * — nunca duplicada en un segundo lugar.
     *
     * @param  array<int, int>  $excludeFromCompletionIds  H16.2 Fase 1.3 — IDs
     *         de WorkoutExercise con un reporte parcial ESTE turno (ver
     *         isPartialReport()): cuentan como "sin resolver" únicamente para
     *         esta decisión de cierre, aunque ya tengan un ExerciseLog real.
     *         Vacío por defecto preserva exactamente el comportamiento previo
     *         — no cambia qué significa "Unreported" para ningún otro
     *         consumidor (CoachContextProvider, SessionCloseIntent, etc.),
     *         que siguen usando `exerciseLog === null` sin este ajuste.
     */
    public function maybeCompleteSession(WorkoutSession $session, array $excludeFromCompletionIds = []): bool
    {
        $session->load(['workoutExercises.exerciseLog']);

        // Hito R1/R2/R3 — `isResolvedForSessionProgression()` sustituye a
        // `exerciseLog === null`: para Main, idéntico (exige ExerciseLog);
        // para Preparation/Cooldown, basta con `delivered_at` (nunca
        // tienen ExerciseLog, por diseño — nunca se les exige uno falso).
        $stillUnreported = $session->workoutExercises->contains(
            fn (WorkoutExercise $we) => ! $we->isResolvedForSessionProgression() || in_array($we->id, $excludeFromCompletionIds, true)
        );

        // H16.2 Fase 1 (fix de la contradicción "pendientes"+"completada"):
        // una sesión NUNCA se cierra mientras exista un ejercicio realmente
        // sin ningún ExerciseLog — sin importar que el usuario haya dicho
        // "ya terminé"/equivalente. Antes de este fix, `session_finished`
        // (el antiguo parámetro $explicitlyFinished) forzaba el cierre
        // incluso con ejercicios genuinamente sin tocar; el único cierre
        // "explícito" válido ahora es el de TrainingHandler::determineSessionCloseIntent()
        // vía SessionCloseIntent::SuccessPartial, que exige que cada
        // ejercicio restante YA tenga un ExerciseLog (aunque sea "no
        // realizado") — nunca uno genuinamente Unreported. Ver
        // docs/DECISIONS.md H16.2.
        if ($stillUnreported) {
            return false;
        }

        $session->update(['status' => WorkoutSessionStatus::Completed, 'completed_at' => now()]);

        Log::info($stillUnreported ? 'TRAINING_SESSION_PARTIALLY_COMPLETED' : 'TRAINING_SESSION_COMPLETED', [
            'workout_session_id' => $session->id,
        ]);

        // Referral Introduction — único punto de despacho de este evento,
        // atómico con la escritura que lo hace verdadero. Ver
        // App\Training\Events\WorkoutSessionCompleted.
        WorkoutSessionCompleted::dispatch($session);

        return true;
    }

    private function nameOf(WorkoutExercise $we): string
    {
        return $we->exercise_snapshot['name'] ?? 'ese ejercicio';
    }

    /**
     * H16.2 Fase 1.2 — lenguaje natural ("3 series de 10 repeticiones con
     * 8kg en Elevaciones de gemelos con mancuernas") en vez de notación
     * técnica ("Elevaciones de gemelos con mancuernas: 10rep@8kg"). Nunca
     * inventa un dato — solo reformula exactamente lo ya persistido.
     * Devuelve una cláusula neutra (sin verbo de apertura ni signo de
     * puntuación final) para que `TrainingHandler` la envuelva con "Registré
     * {esto}." de forma uniforme, sin importar si fue realizado, no
     * realizado, o reportado sin datos cuantificables — nunca celebra
     * automáticamente un "no realizado" (H16.2 Fase 1.2, punto 11).
     */
    private function summaryOf(WorkoutExercise $we, array $report): string
    {
        $name = $this->nameOf($we);

        if ($report['not_performed']) {
            return "que no realizaste {$name}";
        }

        if ($report['sets'] === []) {
            return "tu reporte de {$name}";
        }

        return "{$this->naturalSetsPhrase($report['sets'])} en {$name}";
    }

    /**
     * @param  array<int, array{reps: ?int, load: ?float, duration_seconds: ?int}>  $sets
     */
    private function naturalSetsPhrase(array $sets): string
    {
        $count = count($sets);
        $durations = array_values(array_filter(array_column($sets, 'duration_seconds'), fn ($d) => $d !== null));

        if ($durations !== []) {
            $uniqueDurations = array_unique($durations);

            if (count($uniqueDurations) === 1) {
                $duration = reset($uniqueDurations);

                return $count === 1 ? "1 serie de {$duration} segundos" : "{$count} series de {$duration} segundos";
            }

            return $this->naturalJoin(array_map(fn ($d) => "{$d} segundos", $durations));
        }

        $reps = array_column($sets, 'reps');
        $loads = array_column($sets, 'load');
        $uniqueReps = array_unique(array_filter($reps, fn ($r) => $r !== null));
        $uniqueLoads = array_unique(array_filter($loads, fn ($l) => $l !== null));

        if (count($uniqueReps) === 1) {
            $repsValue = reset($uniqueReps);
            $repsWord = $repsValue === 1 ? 'repetición' : 'repeticiones';
            $repsPhrase = $count === 1
                ? "1 serie de {$repsValue} {$repsWord}"
                : "{$count} series de {$repsValue} {$repsWord}";
        } else {
            $repsPhrase = $this->naturalJoin($reps).' repeticiones';
        }

        if ($uniqueLoads === []) {
            return $repsPhrase;
        }

        $loadPhrase = count($uniqueLoads) === 1
            ? ' con '.$this->formatLoad((float) reset($uniqueLoads)).'kg'
            : ' con '.$this->naturalJoin(array_map(fn ($l) => $this->formatLoad((float) $l).'kg', $loads));

        return $repsPhrase.$loadPhrase;
    }

    /**
     * @param  array<int, string|int|float>  $items
     */
    private function naturalJoin(array $items): string
    {
        $items = array_values($items);

        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }

        $last = array_pop($items);

        return implode(', ', $items).' y '.$last;
    }

    /**
     * Hardening pre-producción (hallazgo E2E de Bloque 9): formatea una
     * carga para el texto de confirmación al usuario — nunca toca el valor
     * persistido (`ExerciseSet.actual_load` guarda el float tal cual, ver
     * persist() arriba). La implementación anterior usaba
     * `rtrim((string) $load, '0')`, que le quitaba el último cero también a
     * enteros como 40 (→ "4") o 20 (→ "2"), no solo a ceros decimales de
     * sobra — number_format() a precisión fija primero, y solo entonces
     * recortar ceros/punto decimal de sobra, evita ese error: 40 → "40",
     * 40.50 → "40.5", 10 → "10".
     */
    private function formatLoad(float $load): string
    {
        return rtrim(rtrim(number_format($load, 2, '.', ''), '0'), '.');
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
