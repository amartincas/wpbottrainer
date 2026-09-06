<?php

namespace App\Training\Enums;

/**
 * Bloque 7 (D050) — dirección de progresión decidida por
 * `ProgressionEvaluator` para un ejercicio específico. Es una decisión
 * DEPORTIVA, nunca médica: `Reduce` significa ajuste conservador de
 * intensidad, no rehabilitación ni diagnóstico (eso sigue siendo de
 * `SafetyRestrictionResolver`/`TrainingAccessGate`/revisión humana, sin
 * relación con este enum). `TrainingEngine` conserva la autoridad exclusiva
 * de traducir esta dirección en números concretos de prescripción.
 */
enum ProgressionDecision: string
{
    case Progress = 'progress';
    case Maintain = 'maintain';
    case Reduce = 'reduce';
    case InsufficientData = 'insufficient_data';
}
