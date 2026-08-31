<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabecera de la ejecución real de un WorkoutExercise — lo que el usuario
 * reportó (RPE general, notas). El detalle serie a serie vive en
 * ExerciseSet. Nunca es fuente de lo prescrito — ver WorkoutExercise.
 */
#[Fillable([
    'workout_exercise_id',
    'rpe',
    'note',
    'logged_at',
])]
class ExerciseLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rpe' => 'integer',
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
