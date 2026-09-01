<?php

namespace App\Models;

use App\Training\Enums\SkipReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabecera de la ejecución real de un WorkoutExercise — lo que el usuario
 * reportó (RPE general, notas). El detalle serie a serie vive en
 * ExerciseSet. Nunca es fuente de lo prescrito — ver WorkoutExercise.
 *
 * `skip_reason` (Hito 8.3): solo se completa cuando `not_performed=true` y
 * el usuario dio o insinuó una razón — nunca inventado por la IA.
 */
#[Fillable([
    'workout_exercise_id',
    'rpe',
    'note',
    'skip_reason',
    'logged_at',
])]
class ExerciseLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rpe' => 'integer',
            'skip_reason' => SkipReason::class,
            'logged_at' => 'datetime',
        ];
    }

    public function workoutExercise(): BelongsTo
    {
        return $this->belongsTo(WorkoutExercise::class);
    }

    public function exerciseSets(): HasMany
    {
        return $this->hasMany(ExerciseSet::class)->orderBy('set_number');
    }
}
