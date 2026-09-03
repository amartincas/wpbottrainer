<?php

use App\ExerciseCatalog\Providers\YMove\YMoveExerciseNormalizer;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseProvider;

/**
 * Hito 9.1 — único lugar donde una clave de proveedor (string simple, ver
 * App\ExerciseCatalog\ProviderRegistry) se conecta a sus implementaciones
 * reales. Agregar un proveedor nuevo (ej. MuscleWiki, Funxtion) es una
 * entrada nueva aquí + las clases correspondientes — nunca un cambio en
 * `Exercise`, `TrainingEngine`, ni una migración.
 */
return [

    'providers' => [
        'ymove' => YMoveExerciseProvider::class,
    ],

    'normalizers' => [
        'ymove' => YMoveExerciseNormalizer::class,
    ],

];
