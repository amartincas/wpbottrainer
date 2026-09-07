<?php

namespace App\Training\Enums;

/**
 * Hito 10 — ciclo de vida EXCLUSIVO del envío de un `Reminder`. Nunca
 * representa la ventana de continuidad conversacional posterior
 * (`Reminder::awaiting_response_until`) — son conceptos deliberadamente
 * separados (ver D053). No se agrega un caso `awaiting_response`: esa
 * ventana es un timestamp aparte, nunca un estado de este enum.
 */
enum ReminderStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
