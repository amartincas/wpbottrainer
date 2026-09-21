<?php

namespace App\Training\Enums;

/**
 * Bloque 6 — vocabulario cerrado del resultado histórico de un
 * `WorkoutExercise`, derivado determinísticamente (nunca por IA). Para
 * ejercicios de bloque principal (`WorkoutExercisePhase::Main`), que sí
 * exigen reporte estructurado:
 * - sin `ExerciseLog` → Unreported;
 * - `ExerciseLog` con al menos un `ExerciseSet` → Performed;
 * - `ExerciseLog` sin ningún `ExerciseSet` → Skipped.
 *
 * Hito R1/R2/R3 — `Delivered` es EXCLUSIVO de preparación/cooldown
 * (`WorkoutExercisePhase::Preparation`/`Cooldown`), que nunca tienen
 * `ExerciseLog` por diseño: "se entregó" (`delivered_at !== null`), sin
 * pretender que hubo un reporte estructurado que esas fases no piden.
 * Evita la semántica incorrecta de etiquetar como `Unreported` ("el
 * usuario no reportó") algo que nunca debía reportarse. Única fuente de
 * esta derivación: `WorkoutExercise::historicalOutcome()` — este enum
 * nunca se deriva de forma independiente en otro archivo.
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
    case Delivered = 'delivered';
}
