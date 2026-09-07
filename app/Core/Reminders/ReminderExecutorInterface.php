<?php

namespace App\Core\Reminders;

use App\Models\Reminder;

/**
 * Hito 10 — contrato para lo que significa EJECUTAR un `Reminder` de un
 * `type` concreto. Core no sabe qué significa "training_weekly" ni cómo se
 * redacta el mensaje — cada implementación de dominio (hoy solo
 * `App\Training\Support\TrainingReminderExecutor`) decide eso. Mismo patrón
 * Container-resuelto que `HandlerInterface`/`IntentClassifierInterface`/
 * `PreRoutingScreenInterface`/`ContextProviderInterface`.
 *
 * Devuelve `true` únicamente cuando hubo una entrega CONFIRMADA (ver
 * `CustomerNotifyResult` — nunca "exactly once"). `ReminderDispatcher`/
 * `SendReminderJob` usan ese booleano para decidir el ciclo de vida del
 * `Reminder` (avanzar a `sent`/recalcular recurrencia vs. dejarlo para
 * reintento) — nunca reinterpretan el resultado por su cuenta.
 */
interface ReminderExecutorInterface
{
    public function execute(Reminder $reminder): bool;
}
