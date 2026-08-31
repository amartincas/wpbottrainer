<?php

namespace App\Training\Enums;

/**
 * split_type es una señal de rotación en TrainingProfile, no un TrainingPlan
 * (decisión aprobada de Hito 4: no crear TrainingPlan todavía). Define solo
 * qué conjuntos de grupos musculares existen para rotar — el Training Engine
 * decide cuál toca realmente evaluando historial, no un calendario fijo.
 */
enum SplitType: string
{
    case FullBody = 'full_body';
    case UpperLower = 'upper_lower';
    case PushPullLegs = 'push_pull_legs';
}
