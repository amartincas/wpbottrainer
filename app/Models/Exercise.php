<?php

namespace App\Models;

use App\Training\Enums\MovementPattern;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\TrackingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'slug',
    'instructions',
    'video_url',
    'muscle_group',
    'primary_muscle',
    'secondary_muscles',
    'movement_pattern',
    'equipment_needed',
    'difficulty_level',
    'contraindications',
    'tracking_type',
    'is_active',
])]
class Exercise extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'primary_muscle' => MuscleFocus::class,
            'secondary_muscles' => 'array',
            'movement_pattern' => MovementPattern::class,
            'equipment_needed' => 'array',
            'contraindications' => 'array',
            'tracking_type' => TrackingType::class,
            'is_active' => 'boolean',
        ];
    }

    public function workoutExercises(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class);
    }

    /**
     * Congela el contenido actual de este ejercicio, para ser guardado como
     * WorkoutExercise::exercise_snapshot en el momento en que el Training
     * Engine genera una sesión. NUNCA debe usarse para reconstruir
     * retroactivamente lo que un usuario recibió en el pasado — para eso
     * existe el snapshot ya guardado, que es inmutable. Ver
     * docs/DECISIONS.md (Hito 4, inmutabilidad histórica).
     *
     * Hito 8.4: incluye `primary_muscle`/`secondary_muscles` para que las
     * pruebas de aceptación de foco (y una futura auditoría de qué recibió
     * un usuario) puedan verificarse directamente sobre el snapshot
     * persistido, sin depender de que el Exercise original no haya
     * cambiado desde entonces.
     */
    public function toSnapshot(): array
    {
        return [
            'name' => $this->name,
            'instructions' => $this->instructions,
            'video_url' => $this->video_url,
            'muscle_group' => $this->muscle_group,
            'primary_muscle' => $this->primary_muscle?->value,
            'secondary_muscles' => $this->secondary_muscles,
        ];
    }
}
