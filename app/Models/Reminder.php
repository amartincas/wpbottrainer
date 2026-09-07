<?php

namespace App\Models;

use App\Training\Enums\ReminderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito 10 — la programación real (única o recurrente) de un recordatorio,
 * ya confirmada explícitamente por el usuario. `status` es EXCLUSIVAMENTE
 * el ciclo de vida del envío; `awaiting_response_until` es EXCLUSIVAMENTE
 * la ventana de continuidad conversacional posterior a un envío exitoso —
 * estos dos conceptos nunca se mezclan (ver docs/DECISIONS.md D053).
 *
 * Máximo un `Reminder` con status `pending`/`sending` por contacto,
 * garantizado por base de datos (índice único sobre columna generada), no
 * por lógica de aplicación — ver la migración `create_reminders_table`.
 */
#[Fillable([
    'tenant_id',
    'contact_id',
    'type',
    'status',
    'fire_at',
    'recurrence',
    'awaiting_response_until',
    'last_fired_at',
    'created_from_suggestion_id',
    'cancelled_at',
    'recovery_attempts',
])]
class Reminder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ReminderStatus::class,
            'fire_at' => 'datetime',
            'recurrence' => 'array',
            'awaiting_response_until' => 'datetime',
            'last_fired_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'recovery_attempts' => 'integer',
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

    public function createdFromSuggestion(): BelongsTo
    {
        return $this->belongsTo(ReminderSuggestion::class, 'created_from_suggestion_id');
    }

    /**
     * El único `Reminder` activo (`pending`/`sending`) del contacto, si
     * existe — mismo criterio que el índice único de la migración, aquí
     * solo para lectura/consulta desde código de dominio.
     */
    public static function activeFor(Contact $contact): ?self
    {
        return static::where('contact_id', $contact->id)
            ->whereIn('status', [ReminderStatus::Pending, ReminderStatus::Sending])
            ->first();
    }

    /**
     * Ventana de continuidad conversacional todavía vigente — NUNCA se lee
     * como si fuera parte del ciclo de vida del envío (`status`). Ver D053.
     */
    public function isAwaitingResponse(): bool
    {
        return $this->awaiting_response_until !== null && $this->awaiting_response_until->isFuture();
    }

    /**
     * Identidad lógica de ESTA ocurrencia — Reminder ID + fire_at actual.
     * Determinista y reproducible: dos ejecuciones (incluida una repetida
     * tras un crash) para la MISMA ocurrencia producen SIEMPRE la misma
     * clave; una ocurrencia recurrente posterior (fire_at ya avanzado)
     * produce una clave distinta, porque es un envío legítimamente nuevo.
     * Único lugar del sistema que construye esta clave — cualquier
     * `ReminderExecutorInterface` la reutiliza tal cual, nunca la
     * recalcula con su propio criterio.
     */
    public function currentOccurrenceIdempotencyKey(): string
    {
        return "reminder:{$this->id}:occurrence:{$this->fire_at->timestamp}";
    }

    /**
     * Transición de ciclo de vida tras un envío ya CONFIRMADO (ver
     * CustomerNotifyResult) — compartida por `SendReminderJob` (camino
     * normal) y `RecoverStuckReminders` (cuando el envío sí se confirmó
     * pero el proceso murió antes de registrar la transición). Único lugar
     * que decide "recurrente -> próxima ocurrencia pendiente" vs "único ->
     * terminal".
     *
     * MVP: la próxima ocurrencia semanal se calcula sumando 7 días exactos
     * en UTC a `fire_at` — no recalcula desde `recurrence` vía
     * ReminderTimeResolver. Limitación conocida y aceptada: en timezones
     * con horario de verano (no aplica a Colombia), esto puede desplazar la
     * hora local en una semana de transición de DST. Documentado, no
     * silenciado.
     */
    public function finalizeConfirmedSend(): void
    {
        if ($this->recurrence !== null) {
            $this->update([
                'status' => ReminderStatus::Pending,
                'fire_at' => $this->fire_at->addWeek(),
                'last_fired_at' => now(),
                'recovery_attempts' => 0,
            ]);

            return;
        }

        $this->update(['status' => ReminderStatus::Sent, 'last_fired_at' => now()]);
    }
}
