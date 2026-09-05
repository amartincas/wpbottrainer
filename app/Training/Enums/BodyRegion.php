<?php

namespace App\Training\Enums;

/**
 * Hito de seguridad de restricciones — vocabulario cerrado y mínimo para
 * el contrato canónico de seguridad (docs/DECISIONS.md). Deliberadamente
 * NO es una ontología médica: son las zonas corporales necesarias para
 * cruzar una restricción de usuario contra una contraindicación de
 * ejercicio de forma determinista.
 *
 * Cada valor corresponde 1:1 a los términos reales ya curados en
 * Exercise.contraindications al momento de este cambio (verificado contra
 * producción, no supuesto) — ver BodyRegionCanonicalMapper::EXACT_MAP.
 */
enum BodyRegion: string
{
    case Shoulder = 'shoulder';
    case Elbow = 'elbow';
    case Wrist = 'wrist';
    case Neck = 'neck';
    case UpperBack = 'upper_back';
    case LowerBack = 'lower_back';
    case Hip = 'hip';
    case Knee = 'knee';
    case Ankle = 'ankle';
    case Chest = 'chest';
    case Abdomen = 'abdomen';
}
