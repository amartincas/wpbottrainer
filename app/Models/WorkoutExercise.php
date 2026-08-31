<?php

namespace App\Models;

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
    'prescribed_sets',
    'prescribed_reps',
    'prescribed_load',
    'prescribed_duration_seconds',
    'rest_seconds',
    'exercise_snapshot',
])]
class WorkoutExercise extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'prescribed_load' => 'decimal:2',
            'exercise_snapshot' => 'array',
        ];
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
}
