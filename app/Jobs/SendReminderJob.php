<?php

namespace App\Jobs;

use App\Core\Reminders\ReminderDispatcher;
use App\Models\Reminder;
use App\Training\Enums\ReminderStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Hito 10 — despachado UNA vez por fila vencida por `reminders:dispatch-due`
 * (scheduler, cada minuto). Se identifica por ID (no por instancia
 * serializada) para que el claim atómico siempre lea el estado más reciente
 * de la fila, nunca uno potencialmente desactualizado desde el momento del
 * despacho.
 *
 * Idempotencia real: el claim `pending -> sending` de abajo es un camino
 * rápido (evita procesar dos veces en el caso común), pero la garantía
 * verdadera de "como máximo un envío confirmado" vive en
 * `CustomerNotifier`/`WhatsAppMessage.idempotency_key` — ver docs/DECISIONS.md.
 * Si este job muere después de confirmar el envío pero antes de terminar,
 * `reminders:recover-stuck` completa la transición sin volver a enviar.
 */
class SendReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $reminderId) {}

    public function handle(ReminderDispatcher $dispatcher): void
    {
        $claimed = Reminder::where('id', $this->reminderId)
            ->where('status', ReminderStatus::Pending->value)
            ->update(['status' => ReminderStatus::Sending->value]);

        if ($claimed === 0) {
            // Ya no está pending — otro worker/retry ya lo tomó, o fue
            // cancelado/modificado entre el dispatch-due y esta ejecución.
            Log::info('REMINDER_CLAIM_SKIPPED', ['reminder_id' => $this->reminderId]);

            return;
        }

        $reminder = Reminder::find($this->reminderId);

        if ($reminder === null) {
            return;
        }

        $confirmed = $dispatcher->dispatch($reminder);

        if (! $confirmed) {
            // Queda en 'sending' — reminders:recover-stuck lo libera más
            // tarde (reintento) o lo marca failed tras 3 intentos.
            Log::warning('REMINDER_SEND_NOT_CONFIRMED', ['reminder_id' => $reminder->id]);

            return;
        }

        $reminder->fresh()->finalizeConfirmedSend();

        Log::info('REMINDER_SENT', ['reminder_id' => $reminder->id, 'type' => $reminder->type]);
    }
}
