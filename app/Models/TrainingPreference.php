<?php

namespace App\Models;

use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\PreferenceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito B3 (Preferencias persistentes) — diseño v3 FINAL. Dominio SEPARADO de
 * `TrainingRestriction`/`DeclaredHealthCondition` (Safety, revisión humana
 * obligatoria) — una Preference nunca requiere revisión, nunca representa
 * riesgo físico, siempre es una declaración explícita del usuario sobre lo
 * que NO quiere.
 *
 * Sin `updated_at`: ninguna transición fuera de create/revoke/reactivate
 * cambia esta fila, y las tres ya tienen su propio timestamp
 * (`created_at`/`revoked_at`/`reactivated_at`) — ver migración. Sin
 * `source`: en el MVP el único origen posible es una declaración explícita
 * del usuario (regla dura: nunca se infiere desde `ExerciseLog.skip_reason`,
 * ver `TrainingPreferenceRecorder`) — una columna con un solo valor real no
 * tiene consumidor.
 *
 * Lifecycle exacto (diseño v3, Sección A.1) — nunca implementado aquí, solo
 * declarado: única autoridad de escritura es `TrainingPreferenceRecorder`.
 * Este modelo no tiene ningún método de mutación propio, a propósito — evita
 * que un caller escriba `status`/`revoked_at`/`reactivated_at` directamente
 * sin pasar por la idempotencia/unicidad que el Recorder garantiza.
 */
#[Fillable([
    'contact_id',
    'dimension',
    'exercise_id',
    'equipment_value',
    'preference_key',
    'status',
    'original_text',
    'created_at',
    'revoked_at',
    'reactivated_at',
])]
class TrainingPreference extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'dimension' => PreferenceDimension::class,
            'status' => PreferenceStatus::class,
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
            'reactivated_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function isActive(): bool
    {
        return $this->status === PreferenceStatus::Active;
    }
}
