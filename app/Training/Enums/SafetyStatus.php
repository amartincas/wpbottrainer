<?php

namespace App\Training\Enums;

/**
 * Estado de la política de seguridad determinista (Hito 4) — nunca escrito
 * por el LLM. Ver App\Training\Support\TrainingAccessGate y
 * App\Training\Support\SafetySignalDetector.
 */
enum SafetyStatus: string
{
    case Normal = 'normal';
    case FlaggedForReview = 'flagged_for_review';
}
