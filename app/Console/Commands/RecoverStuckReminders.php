<?php

namespace App\Console\Commands;

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertService;
use App\Core\Alerts\AlertSeverity;
use App\Models\Reminder;
use App\Models\WhatsAppMessage;
use App\Training\Enums\ReminderStatus;
use Illuminate\Console\Command;

/**
 * Hito 10 — programado cada 5 minutos (ver routes/console.php). Libera
 * `Reminder` atascados en `sending` (el worker murió antes de completar la
 * transición, sin que los `tries` de Laravel llegaran a intervenir).
 *
 * Comprueba explícitamente `WhatsAppMessage.dispatch_confirmed_at` antes de
 * decidir: si el envío YA se confirmó (el crash ocurrió después de
 * entregarlo mas antes de marcar la fila), finaliza directamente sin volver
 * a ejecutar el Reminder — evita una llamada de IA/envío innecesarios y
 * usa exactamente el mismo camino de finalización que `SendReminderJob`.
 * Si el resultado sigue siendo desconocido, libera a `pending` (para que
 * `reminders:dispatch-due` lo reintente) hasta 3 veces; a partir de ahí
 * queda `failed` de forma permanente y se alerta.
 */
class RecoverStuckReminders extends Command
{
    protected $signature = 'reminders:recover-stuck';

    protected $description = 'Recovers Reminder rows stuck in "sending" — never an infinite retry, never a lost reminder.';

    private const STUCK_THRESHOLD_MINUTES = 10;

    private const MAX_RECOVERY_ATTEMPTS = 3;

    public function __construct(private readonly AlertService $alerts)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $stuck = Reminder::where('status', ReminderStatus::Sending->value)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_THRESHOLD_MINUTES))
            ->get();

        foreach ($stuck as $reminder) {
            $this->recover($reminder);
        }

        $this->info("Processed {$stuck->count()} stuck reminder(s).");

        return self::SUCCESS;
    }

    private function recover(Reminder $reminder): void
    {
        $confirmedMessage = WhatsAppMessage::where('idempotency_key', $reminder->currentOccurrenceIdempotencyKey())
            ->whereNotNull('dispatch_confirmed_at')
            ->exists();

        if ($confirmedMessage) {
            // El envío sí se confirmó antes del crash — finaliza igual que
            // SendReminderJob habría hecho, sin reintentar nada.
            $reminder->finalizeConfirmedSend();

            return;
        }

        if ($reminder->recovery_attempts >= self::MAX_RECOVERY_ATTEMPTS) {
            $reminder->update(['status' => ReminderStatus::Failed->value]);

            $this->alerts->send(new Alert(
                category: 'reminders',
                severity: AlertSeverity::Critical,
                message: "Reminder #{$reminder->id} agotó sus reintentos de recuperación y quedó marcado failed.",
                context: [
                    'reminder_id' => $reminder->id,
                    'tenant_id' => $reminder->tenant_id,
                    'contact_id' => $reminder->contact_id,
                    'recovery_attempts' => $reminder->recovery_attempts,
                ],
            ));

            return;
        }

        $reminder->update([
            'status' => ReminderStatus::Pending->value,
            'recovery_attempts' => $reminder->recovery_attempts + 1,
        ]);
    }
}
