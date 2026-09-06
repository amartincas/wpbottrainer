<?php

namespace App\Training\Enums;

/**
 * Bloque 6 — vocabulario cerrado del resultado histórico de un
 * `WorkoutExercise`, derivado determinísticamente (nunca por IA):
 * - sin `ExerciseLog` → Unreported;
 * - `ExerciseLog` con al menos un `ExerciseSet` → Performed;
 * - `ExerciseLog` sin ningún `ExerciseSet` → Skipped.
 *
 * Mismo patrón que el resto de vocabularios cerrados del dominio
 * (WorkoutSessionStatus, SkipReason, RestrictionStatus, etc.) — un enum
 * backed, nunca un string libre.
 */
enum HistoryExerciseOutcome: string
{
    case Performed = 'performed';
    case Skipped = 'skipped';
    case Unreported = 'unreported';
}
