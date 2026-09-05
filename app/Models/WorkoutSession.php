<?php

namespace App\Models;

use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'contact_id',
    'status',
    'scheduled_at',
    'completed_at',
    'generated_by',
    'prescription_context_snapshot',
])]
class WorkoutSession extends Model
{
    use HasFactory;

    /**
     * Bloque 3 — congelado UNA SOLA VEZ por `TrainingEngine::decideNextSession()`
     * en el momento de creación (ver `TrainingProfile::toPrescriptionContextSnapshot()`).
     * Inmutable por convención — igual que `WorkoutExercise.exercise_snapshot` —
     * ningún otro punto del código debe reescribirlo. `null` en sesiones
     * creadas antes de este bloque: nunca se rellena retroactivamente,
     * porque no hay forma de reconstruir con certeza el contexto real usado
     * en aquel momento (ver docs/DECISIONS.md).
     *
     * Fundación Temporal (D046): su clave `generated_at` es `prescribed_at`
     * conceptual — el instante de la decisión, NUNCA el de entrega por
     * WhatsApp — distinto de `scheduled_at` de esta misma fila (el instante
     * para el que la sesión está prevista), aunque hoy coincidan porque
     * `TrainingEngine` no soporta programación anticipada.
     */

    protected function casts(): array
    {
        return [
            'status' => WorkoutSessionStatus::class,
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'prescription_context_snapshot' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function workoutExercises(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class)->orderBy('order');
    }
}
