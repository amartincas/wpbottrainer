<?php

namespace App\ExerciseCatalog\Enums;

/**
 * Hito 9.1 — variantes de un mismo recurso de video que un proveedor puede
 * ofrecer (ej. YMove: fondo blanco vs. grabado en gimnasio). Vocabulario
 * propio de WpbotTrainer, nunca los nombres/tags exactos de un proveedor
 * (YMove usa "white-background"/"gym-shot" internamente — la traducción
 * vive únicamente dentro de cada Adapter).
 */
enum MediaVariant: string
{
    case Default = 'default';
    case WhiteBackground = 'white_background';
    case GymShot = 'gym_shot';
}
