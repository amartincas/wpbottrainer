<?php

namespace App\Training\Enums;

/**
 * Hito B3.1 — ciclo de vida de una `TrainingPreferenceClarification`.
 * `Expired` es un estado explícito (nunca se infiere solo de `expires_at` en
 * el pasado) — lo fija el código al comprobar la expiración de forma
 * perezosa (mismo criterio que `ReminderSuggestionStatus`), para que una
 * consulta simple por `status` sea suficiente. Ningún estado implica borrado
 * — las cuatro transiciones son mutuamente excluyentes y definitivas
 * (una fila `resolved`/`abandoned`/`expired` nunca vuelve a `pending`; una
 * clarificación nueva siempre es una fila NUEVA, ver
 * `TrainingPreferenceClarificationRecorder::create()`).
 */
enum TrainingPreferenceClarificationStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Abandoned = 'abandoned';
    case Expired = 'expired';
}
