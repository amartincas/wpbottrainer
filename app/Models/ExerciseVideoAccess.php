<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito 9.3 (post-deploy) — un registro por cada vez que
 * App\ExerciseCatalog\MediaResolver::resolve() obtuvo un ResolvedMedia no
 * nulo de un proveedor real. Append-only (nunca se actualiza ni se
 * borra salvo por la cascada de su Exercise). Única fuente de verdad de
 * "video validado" — ver Exercise::reviewStatus() para el criterio
 * equivalente de revisión de contraindicaciones, mismo patrón de
 * "derivado, nunca inventado".
 */
#[Fillable([
    'exercise_id',
    'provider',
    'provider_exercise_id',
    'variant',
    'resolved_at',
])]
class ExerciseVideoAccess extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
