<?php

namespace App\Core\Notifications;

/**
 * Hito 10 — resultado de `CustomerNotifier::notify()`. `confirmed` es
 * `true` únicamente cuando existe una entrega CONFIRMADA (ahora mismo o en
 * un intento anterior) — nunca "exactly once", "como máximo una entrega
 * confirmada" (ver docs/DECISIONS.md). Los llamadores que usan
 * `idempotencyKey` (Hito 10, `SendReminderJob`) lo consultan para decidir
 * si avanzan su propio ciclo de vida (`Reminder.status`); los llamadores
 * existentes sin idempotencyKey (Payments) pueden ignorarlo por completo —
 * `notify()` sigue sin propagar excepciones.
 */
final readonly class CustomerNotifyResult
{
    private function __construct(
        public bool $confirmed,
    ) {}

    public static function confirmed(): self
    {
        return new self(true);
    }

    public static function unconfirmed(): self
    {
        return new self(false);
    }
}
