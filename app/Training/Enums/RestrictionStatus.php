<?php

namespace App\Training\Enums;

/**
 * Hito de seguridad de restricciones. Solo `Confirmed` participa en
 * TrainingEngine::isEligible() (vía SafetyRestrictionResolver) — es el
 * mecanismo real que impide que una declaración se confunda con una
 * autorización. `PendingReview` nunca excluye ni permite nada por sí solo.
 */
enum RestrictionStatus: string
{
    case PendingReview = 'pending_review';
    case Confirmed = 'confirmed';
    case Resolved = 'resolved';
    case Superseded = 'superseded';
}
