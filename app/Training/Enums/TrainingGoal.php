<?php

namespace App\Training\Enums;

/**
 * Vocabulario cerrado de objetivos de entrenamiento (Hito 4). Backed enum en
 * vez de tabla — mismo criterio que App\Core\Messaging\Intent: es un
 * vocabulario pequeño y estable, sin necesidad de administración en caliente
 * todavía.
 */
enum TrainingGoal: string
{
    case LoseWeight = 'lose_weight';
    case BuildMuscle = 'build_muscle';
    case GeneralFitness = 'general_fitness';
    case Endurance = 'endurance';
}
