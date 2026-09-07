<?php

namespace App\Core\Reminders;

use App\Models\Reminder;
use Illuminate\Contracts\Container\Container;

/**
 * Hito 10 — resuelve, por `Reminder.type`, el `ReminderExecutorInterface`
 * que sabe ejecutarlo. Mismo patrón Container-resuelto (mapa de CLASES, no
 * instancias) que `App\Core\Messaging\Dispatcher`/`Router`. Cero
 * conocimiento de dominio aquí — nunca sabe qué es un "training_weekly".
 */
class ReminderDispatcher
{
    /**
     * @param  array<string, class-string<ReminderExecutorInterface>>  $executorClasses
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $executorClasses,
    ) {}

    public function dispatch(Reminder $reminder): bool
    {
        $executorClass = $this->executorClasses[$reminder->type] ?? null;

        if ($executorClass === null) {
            throw new \RuntimeException("No ReminderExecutor registered for Reminder.type: {$reminder->type}");
        }

        /** @var ReminderExecutorInterface $executor */
        $executor = $this->container->make($executorClass);

        return $executor->execute($reminder);
    }
}
