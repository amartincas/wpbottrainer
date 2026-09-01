<?php

namespace App\Training\Enums;

/**
 * Hito 8.3 — dónde entrena el usuario. Bloqueante para el onboarding (a
 * diferencia de los datos físicos) porque resuelve directamente la
 * ambigüedad real de equipo ("de todo" en un gimnasio) encontrada en el E2E
 * comercial — ver docs/DECISIONS.md.
 */
enum TrainingLocation: string
{
    case Home = 'home';
    case Gym = 'gym';
    case Outdoor = 'outdoor';
}
