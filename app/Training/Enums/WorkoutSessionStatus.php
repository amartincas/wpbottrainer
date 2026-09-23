<?php

namespace App\Training\Enums;

enum WorkoutSessionStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Skipped = 'skipped';

    /**
     * Hito B2 (Nueva rutina durante sesión activa) — la sesión fue
     * reemplazada por una nueva a petición EXPLÍCITA del usuario
     * ("quiero otra rutina"), vía `ReplaceWorkoutSessionService`. Nunca se
     * escribe por ningún otro motivo.
     *
     * Deliberadamente DISTINTO de `Skipped` — no es el mismo concepto ni
     * comparte su semántica en ningún consumidor: `Skipped` (auditoría
     * B2.2, `TrainingEngine::decideFocus()`) dispara un REINTENTO del
     * mismo foco autónomo en la sesión siguiente ("esto no se hizo, hay
     * que reintentarlo") — `Superseded` NUNCA debe disparar ese
     * comportamiento, porque no significa "no se hizo", significa "el
     * usuario pidió explícitamente otra cosa". Ver `WorkoutSession::
     * supersededBy()`/`supersededSession()` para la relación con la sesión
     * que la reemplazó/que reemplazó.
     */
    case Superseded = 'superseded';
}
