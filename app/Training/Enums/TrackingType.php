<?php

namespace App\Training\Enums;

/**
 * Distingue ejercicios por repeticiones/carga de ejercicios por tiempo, sin
 * necesitar una entidad separada — ver docs/DECISIONS.md (Hito 4, punto 1).
 */
enum TrackingType: string
{
    case RepsAndLoad = 'reps_and_load';
    case TimeBased = 'time_based';
}
