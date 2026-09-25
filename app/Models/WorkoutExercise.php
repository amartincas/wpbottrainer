<?php

namespace App\Models;

use App\Training\Enums\HistoryExerciseOutcome;
use App\Training\Enums\WorkoutExercisePhase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Lo que el Training Engine prescribió y mostró — inmutable desde su
 * creación (ver docs/DECISIONS.md, Hito 4). Nunca se sobrescribe con lo que
 * el usuario ejecutó realmente; eso vive en ExerciseLog/ExerciseSet.
 */
#[Fillable([
    'workout_session_id',
    'exercise_id',
    'order',
    'phase',
    'prescribed_sets',
    'prescribed_reps',
    'prescribed_load',
    'prescribed_duration_seconds',
    'rest_seconds',
    'exercise_snapshot',
    'delivered_at',
    // Hito C (Sustitución de un ejercicio) — escrito EXCLUSIVAMENTE por
    // ReplaceWorkoutExerciseService, nunca en la prescripción normal de
    // TrainingEngine::decideNextSession().
    'superseded_by_id',
])]
class WorkoutExercise extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'phase' => WorkoutExercisePhase::class,
            'prescribed_load' => 'decimal:2',
            'exercise_snapshot' => 'array',
            // P1-A — momento real de entrega del mensaje de técnica de ESTE
            // ejercicio (ver TrainingHandler::deliverExercise()), nunca de
            // creación de la fila. Null en ejercicios creados antes de este
            // cambio — sin backfill (ver migración).
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Hito R1/R2/R3 — solo el bloque principal (`Main`) exige un reporte
     * estructurado real (`ExerciseLog`). Preparación/Cooldown nunca lo
     * requieren — decisión de producto, no una limitación técnica. Única
     * fuente de esta regla: todo lo demás (`ExecutionReportRecorder`,
     * `TrainingHandler`, `UnreportedExerciseDetector`,
     * `ActiveWorkoutSessionContextProvider`) la reutiliza, nunca la
     * reimplementa.
     */
    public function requiresExecutionReport(): bool
    {
        return $this->phase === WorkoutExercisePhase::Main;
    }

    /**
     * Hito R1/R2/R3 — "resuelto para efectos de avance/cierre de sesión".
     * NO es sinónimo de `delivered_at` ni de "completado" en general: para
     * `Main` exige un `ExerciseLog` real (idéntico al criterio ya existente
     * antes de este hito, sin cambios). Para `Preparation`/`Cooldown` basta
     * con que se haya ENTREGADO — nunca se crea un `ExerciseLog` falso para
     * simular un reporte que estas fases no piden.
     */
    public function isResolvedForSessionProgression(): bool
    {
        return $this->requiresExecutionReport()
            ? $this->exerciseLog !== null
            : $this->delivered_at !== null;
    }

    /**
     * Hito R1/R2/R3 — única fuente de la derivación de
     * `HistoryExerciseOutcome` para este `WorkoutExercise`, reutilizada por
     * `TrainingHistoryContextProvider`/`CoachContextProvider` en vez de que
     * cada uno reimplemente el mismo match. Para `Main`: idéntico al
     * criterio ya existente (sin `ExerciseLog` → Unreported; con log sin
     * sets → Skipped; con log y sets → Performed). Para
     * `Preparation`/`Cooldown` (que nunca tienen `ExerciseLog`, por diseño):
     * `Delivered` si se entregó, `Unreported` como caso defensivo si por
     * algún motivo nunca llegó a entregarse — nunca `Performed`/`Skipped`,
     * que implicarían un reporte estructurado que estas fases no piden.
     */
    public function historicalOutcome(): HistoryExerciseOutcome
    {
        if (! $this->requiresExecutionReport()) {
            return $this->delivered_at !== null
                ? HistoryExerciseOutcome::Delivered
                : HistoryExerciseOutcome::Unreported;
        }

        return match (true) {
            $this->exerciseLog === null => HistoryExerciseOutcome::Unreported,
            $this->exerciseLog->exerciseSets->isEmpty() => HistoryExerciseOutcome::Skipped,
            default => HistoryExerciseOutcome::Performed,
        };
    }

    public function workoutSession(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class);
    }

    /**
     * Referencia únicamente para trazabilidad/analítica (ej. "cuántas veces
     * se prescribió sentadilla"). NUNCA debe usarse para reconstruir el
     * contenido que el usuario recibió en este registro — para eso existe
     * exercise_snapshot, que es inmutable y no depende de que este Exercise
     * siga existiendo o sin cambios. Ver docs/DECISIONS.md.
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function exerciseLog(): HasOne
    {
        return $this->hasOne(ExerciseLog::class);
    }

    /**
     * Hito C (Sustitución de un ejercicio) — el `WorkoutExercise` NUEVO que
     * sustituyó a este (`superseded_by_id`), si este fue sustituido por
     * `ReplaceWorkoutExerciseService`. `null` en cualquier otro caso
     * (incluido cualquier ejercicio creado antes de este hito). Mismo
     * criterio exacto que `WorkoutSession::supersededBy()`.
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * Hito C — inversa de `supersededBy()`: el `WorkoutExercise` VIEJO que
     * este sustituyó, si este fue creado por `ReplaceWorkoutExerciseService`
     * como reemplazo de otro. `null` para cualquier ejercicio creado por el
     * flujo normal de `TrainingEngine::decideNextSession()` sin sustitución.
     * Mismo criterio exacto que `WorkoutSession::supersededSession()`.
     */
    public function supersededExercise(): HasOne
    {
        return $this->hasOne(self::class, 'superseded_by_id');
    }
}
