<?php

namespace App\ExerciseCatalog\Contracts;

use App\ExerciseCatalog\DTOs\NormalizedExerciseData;
use App\ExerciseCatalog\DTOs\ProviderExerciseData;

/**
 * Hito 9.1 — traduce el shape crudo de UN proveedor (`ProviderExerciseData`,
 * todavía en su propio vocabulario) al vocabulario cerrado de WpbotTrainer
 * (`NormalizedExerciseData`, 100% enums propios). Un implementador por
 * proveedor (ej. YMoveExerciseNormalizer) — nunca lógica compartida que
 * intente adivinar el shape de un proveedor genérico.
 */
interface ExerciseNormalizerInterface
{
    public function normalize(ProviderExerciseData $raw): NormalizedExerciseData;
}
