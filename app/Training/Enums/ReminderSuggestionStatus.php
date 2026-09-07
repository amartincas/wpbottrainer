<?php

namespace App\Training\Enums;

/**
 * Hito 10 — ciclo de vida de una `ReminderSuggestion`, previa a crear un
 * `Reminder` real. `Expired` es un estado explícito (nunca se infiere solo
 * de `expires_at` en el pasado) — lo fija el código al comprobar la
 * expiración, para que una consulta simple por `status` sea suficiente.
 */
enum ReminderSuggestionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
}
