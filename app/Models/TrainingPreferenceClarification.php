<?php

namespace App\Models;

use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\TrainingPreferenceClarificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito B3.1 (Estado conversacional para clarificaciones de preferencias) —
 * estado transitorio, PREVIO a `TrainingPreference`: representa "se le
 * preguntó al usuario a cuál ejercicio se refería, la pregunta sigue sin
 * respuesta resolutiva". Nunca representa una preferencia en sí — mismo
 * criterio de separación que `ReminderSuggestion` frente a `Reminder`.
 *
 * Este modelo NO tiene métodos de mutación propios (salvo la corrección
 * perezosa de expiración en `isActionable()`, mismo patrón que
 * `ReminderSuggestion`) — la única autoridad de escritura del lifecycle
 * (crear/resolver/abandonar) es `TrainingPreferenceClarificationRecorder`,
 * nunca este modelo ni `TrainingHandler` directamente.
 *
 * Deliberadamente sin `updated_at`: cada transición real ya tiene su propio
 * timestamp dedicado (`resolved_at`/`abandoned_at`/`expired_at`), mismo
 * criterio que `TrainingPreference`.
 */
#[Fillable([
    'contact_id',
    'dimension',
    'original_candidate_term',
    'original_text',
    'presented_options',
    'total_matches',
    'status',
    'expires_at',
    'created_at',
    'resolved_at',
    'abandoned_at',
    'expired_at',
])]
class TrainingPreferenceClarification extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'dimension' => PreferenceDimension::class,
            'presented_options' => 'array',
            'total_matches' => 'integer',
            'status' => TrainingPreferenceClarificationStatus::class,
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'resolved_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * `pending` cuya `expires_at` ya pasó se considera funcionalmente
     * expirada aunque el valor persistido siga diciendo `pending` — se
     * corrige perezosamente aquí (mismo criterio exacto que
     * `ReminderSuggestion::isActionable()`, Hito 10), sin necesitar un job
     * de barrido dedicado.
     */
    public function isActionable(): bool
    {
        if ($this->status !== TrainingPreferenceClarificationStatus::Pending) {
            return false;
        }

        if ($this->expires_at->isPast()) {
            $this->update([
                'status' => TrainingPreferenceClarificationStatus::Expired,
                'expired_at' => now(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * La `TrainingPreferenceClarification` `pending` (y no expirada) más
     * reciente del contacto, si existe — la restricción real de "como mucho
     * una pending por contacto" vive en la base de datos (índice único sobre
     * columna generada, ver la migración), esto es solo lectura. Mismo
     * criterio exacto que `ReminderSuggestion::activePendingFor()`.
     */
    public static function activePendingFor(Contact $contact): ?self
    {
        $clarification = static::where('contact_id', $contact->id)
            ->where('status', TrainingPreferenceClarificationStatus::Pending)
            ->latest('id')
            ->first();

        return $clarification !== null && $clarification->isActionable() ? $clarification : null;
    }
}
