<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cada serie realmente ejecutada — el nivel de detalle más fino del
 * historial real (ej. 10x40kg, 10x45kg, 8x50kg en la misma sesión).
 * actual_reps/actual_load/actual_duration_seconds son nullable porque un
 * ejercicio es o por repeticiones/carga o por tiempo, nunca ambos.
 */
#[Fillable([
    'exercise_log_id',
    'set_number',
    'actual_reps',
    'actual_load',
    'actual_duration_seconds',
])]
class ExerciseSet extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'actual_load' => 'decimal:2',
        ];
    }

    public function exerciseLog(): BelongsTo
    {
        return $this->belongsTo(ExerciseLog::class);
    }
}
