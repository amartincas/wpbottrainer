<?php

namespace App\Training\Support;

/**
 * Hito B2 (Nueva rutina durante sesión activa) — lanzada por
 * `ReplaceWorkoutSessionService::replace()` cuando encuentra MÁS de una
 * `WorkoutSession` con `status=Scheduled` para el mismo `Contact` en el
 * momento del reemplazo (auditoría B2.1/B2.2: el sistema espera
 * conceptualmente una sola, pero no existe ninguna constraint de base de
 * datos que lo garantice — ver docblock de `ReplaceWorkoutSessionService`).
 *
 * Deliberadamente NUNCA se resuelve eligiendo una arbitrariamente (`latest()`
 * o similar) — eso ocultaría una inconsistencia real de datos detrás de un
 * comportamiento silencioso. El llamador (`TrainingHandler`) debe capturarla
 * y responder de forma segura, nunca dejar que rompa el turno sin explicación
 * ni perder la traza (ver `TrainingHandler::executeTurnActions()`, rama
 * `NewWorkoutRequest`).
 */
class MultipleActiveWorkoutSessionsException extends \RuntimeException
{
    public function __construct(public readonly int $contactId, public readonly int $scheduledCount)
    {
        parent::__construct("Contact {$contactId} has {$scheduledCount} Scheduled WorkoutSessions — expected at most 1.");
    }
}
