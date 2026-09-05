<?php

namespace App\Training\Enums;

/**
 * Hito de seguridad de restricciones (Bloque 2). Distinto del enum
 * `RestrictionStatus` del Bloque 1 — un ciclo de vida diferente: una
 * `DeclaredHealthCondition` nunca llega a "confirmed" por sí misma, solo
 * se resuelve (con o sin una TrainingRestriction resultante) o se
 * reemplaza por una declaración posterior.
 */
enum HealthConditionStatus: string
{
    case PendingReview = 'pending_review';
    case ResolvedNoRestriction = 'resolved_no_restriction';
    case ResolvedRestrictionCreated = 'resolved_restriction_created';
    case Superseded = 'superseded';
}
