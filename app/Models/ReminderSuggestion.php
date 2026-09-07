<?php

namespace App\Models;

use App\Training\Enums\ReminderSuggestionOrigin;
use App\Training\Enums\ReminderSuggestionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito 10 — propuesta efímera de recordatorio, previa a la confirmación
 * explícita del usuario. NUNCA crea un `Reminder` por sí sola — ver
 * `ConversationTurnResolver`/`ApplyReminderDecision`. `proposed_params` ya
 * viene resuelto por `ReminderTimeResolver` (nunca el texto crudo del
 * usuario) — ver docs/DECISIONS.md (D053).
 */
#[Fillable([
    'tenant_id',
    'contact_id',
    'origin',
    'trigger_reason',
    'proposed_type',
    'proposed_params',
    'status',
    'expires_at',
])]
class ReminderSuggestion extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'origin' => ReminderSuggestionOrigin::class,
            'proposed_params' => 'array',
            'status' => ReminderSuggestionStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * `pending` cuya `expires_at` ya pasó se considera funcionalmente
     * expirada aunque el valor persistido siga diciendo `pending` — se
     * corrige perezosamente aquí (sin necesitar un job de barrido dedicado,
     * fuera del alcance de este hito) para que cualquier consumidor
     * posterior (reportes, `ReminderProactivityGate`) vea el estado real.
     */
    public function isActionable(): bool
    {
        if ($this->status !== ReminderSuggestionStatus::Pending) {
            return false;
        }

        if ($this->expires_at->isPast()) {
            $this->update(['status' => ReminderSuggestionStatus::Expired]);

            return false;
        }

        return true;
    }

    /**
     * La `ReminderSuggestion` `pending` (y no expirada) más reciente del
     * contacto, si existe — la aplicación de la restricción de "como mucho
     * una `pending` por contacto" real vive en la base de datos (índice
     * único sobre columna generada, ver la migración), esto es solo lectura.
     */
    public static function activePendingFor(Contact $contact): ?self
    {
        $suggestion = static::where('contact_id', $contact->id)
            ->where('status', ReminderSuggestionStatus::Pending)
            ->latest('id')
            ->first();

        return $suggestion !== null && $suggestion->isActionable() ? $suggestion : null;
    }
}
