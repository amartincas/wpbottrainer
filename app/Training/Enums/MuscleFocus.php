<?php

namespace App\Training\Enums;

/**
 * Hito 8.4 — vocabulario cerrado de zonas musculares específicas, más fino
 * que `muscle_group` (que agrupa "legs" como un solo bucket indiferenciado
 * de cuádriceps/isquiotibiales/glúteos/pantorrillas). Usado por
 * TrainingProfile.primary_focus/secondary_focus Y por
 * Exercise.primary_muscle/secondary_muscles — el mismo vocabulario en
 * ambos lados es lo que permite comparar directamente sin una capa de
 * traducción intermedia (mismo criterio ya usado para
 * restrictions/contraindications y available_equipment/equipment_needed).
 *
 * El usuario nunca ve ni elige estos valores técnicos — la IA los deriva
 * de lenguaje natural (ver OnboardingConversationService); el código nunca
 * inventa un valor fuera de esta lista.
 */
enum MuscleFocus: string
{
    case Glutes = 'glutes';
    case Quads = 'quads';
    case Hamstrings = 'hamstrings';
    case Calves = 'calves';
    case Chest = 'chest';
    case Back = 'back';
    case Shoulders = 'shoulders';
    case Biceps = 'biceps';
    case Triceps = 'triceps';
    case Abs = 'abs';
    case FullBody = 'full_body';
}
