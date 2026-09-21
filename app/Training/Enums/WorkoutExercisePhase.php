<?php

namespace App\Training\Enums;

/**
 * Hito R1/R2/R3 — rol funcional de un `WorkoutExercise` dentro de la
 * sesión: preparación (calentamiento/movilidad), bloque principal
 * (entrenamiento real, lo único que existía antes de este hito) y
 * finalización (vuelta a la calma/estiramiento). Vocabulario cerrado,
 * mismo patrón que `WorkoutSessionStatus`/`HistoryExerciseOutcome`.
 *
 * `Main` es el default histórico (ver migración de la columna `phase`,
 * `DEFAULT 'main'`) — todo `WorkoutExercise` creado antes de este hito se
 * considera `Main` sin ningún backfill explícito de datos.
 */
enum WorkoutExercisePhase: string
{
    case Preparation = 'preparation';
    case Main = 'main';
    case Cooldown = 'cooldown';
}
