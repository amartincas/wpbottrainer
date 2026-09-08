<?php

namespace App\Training\Enums;

/**
 * Hito 12 — vocabulario cerrado de transiciones ADMINISTRATIVAS auditadas
 * en `TrainingAccessAudit`. Deliberadamente cerrado (a diferencia de
 * `Alert::category`, libre a propósito porque sirve a dominios ajenos) —
 * el conjunto de acciones que `TrainingAccessAdministrationService` puede
 * realizar es finito y conocido de antemano.
 */
enum TrainingAccessAuditAction: string
{
    case TrialGranted = 'trial_granted';
    case FreeGranted = 'free_granted';
    case Extended = 'extended';
    case Revoked = 'revoked';
    case Reactivated = 'reactivated';
}
