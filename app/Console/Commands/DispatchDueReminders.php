<?php

namespace App\Console\Commands;

use App\Jobs\SendReminderJob;
use App\Models\Reminder;
use App\Training\Enums\ReminderStatus;
use Illuminate\Console\Command;

/**
 * Hito 10 — programado cada minuto (ver routes/console.php). Solo
 * SELECCIONA filas vencidas y despacha un job por cada una — nunca envía
 * nada él mismo (la lógica de envío/idempotencia vive en `SendReminderJob`/
 * `CustomerNotifier`).
 */
class DispatchDueReminders extends Command
{
    protected $signature = 'reminders:dispatch-due';

    protected $description = 'Dispatches one SendReminderJob for every Reminder whose fire_at is due.';

    public function handle(): int
    {
        $due = Reminder::where('status', ReminderStatus::Pending->value)
            ->where('fire_at', '<=', now())
            ->pluck('id');

        foreach ($due as $reminderId) {
            SendReminderJob::dispatch($reminderId);
        }

        $this->info("Dispatched {$due->count()} due reminder(s).");

        return self::SUCCESS;
    }
}
