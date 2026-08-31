<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Training\Enums\SafetyStatus;

/**
 * Frontera única entre el dominio Training y (a) el sistema comercial de
 * acceso y (b) la política de seguridad determinista. Ningún Handler ni el
 * Training Engine debe generar contenido de entrenamiento sin pasar por
 * aquí primero — ver docs/DECISIONS.md (Hito 4).
 *
 * Deliberadamente NO conoce Subscription/Payment/Invoice/Enrollment — ese
 * es exactamente el punto: cuando el hito de Payments llegue, solo cambia
 * lo que hay dentro de este método, Training Engine/Handlers no se tocan.
 *
 * Verifica dos fuentes con dueños distintos, en un solo checkpoint:
 * - TrainingProfile.safety_status (política de seguridad, ver
 *   App\Training\Support\SafetySignalDetector).
 * - TrainingAccess (entitlement comercial).
 *
 * Motivos de bloqueo posibles: 'safety_flagged', 'no_access', 'access_invalid'
 * (fila de TrainingAccess existente pero no vigente — expirada o revocada).
 */
class TrainingAccessGate
{
    public function authorize(Contact $contact): AccessGateResult
    {
        $profile = $contact->trainingProfile;

        if ($profile !== null && $profile->safety_status === SafetyStatus::FlaggedForReview) {
            return AccessGateResult::deny('safety_flagged');
        }

        $access = $contact->trainingAccess;

        if ($access === null) {
            return AccessGateResult::deny('no_access');
        }

        if (! $access->isCurrentlyValid()) {
            return AccessGateResult::deny('access_invalid');
        }

        return AccessGateResult::allow();
    }
}
