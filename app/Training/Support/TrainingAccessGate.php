<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Training\Enums\SafetyStatus;

/**
 * Frontera única entre el dominio Training y (a) el sistema comercial de
 * acceso, (b) la política de seguridad determinista, y (c) desde el
 * Bloque 5, el screening de salud previo a la primera rutina. Ningún
 * Handler ni el Training Engine debe generar contenido de entrenamiento
 * sin pasar por aquí primero — ver docs/DECISIONS.md (Hito 4, D048).
 *
 * Deliberadamente NO conoce Subscription/Payment/Invoice/Enrollment — ese
 * es exactamente el punto: cuando el hito de Payments llegue, solo cambia
 * lo que hay dentro de este método, Training Engine/Handlers no se tocan.
 * El Bloque 5 sigue el mismo principio: `TrainingEngine` no gana ninguna
 * línea nueva, todo el bloqueo vive aquí.
 *
 * Verifica tres fuentes con dueños distintos, en un solo checkpoint:
 * - TrainingProfile.safety_status (política de seguridad, ver
 *   App\Training\Support\SafetySignalDetector).
 * - DeclaredHealthCondition pendiente de revisión (Bloque 5) — SOLO antes
 *   de la primera rutina (contacto sin ninguna WorkoutSession todavía).
 * - TrainingAccess (entitlement comercial).
 *
 * Motivos de bloqueo posibles: 'safety_flagged', 'health_screening_pending',
 * 'no_access', 'access_invalid' (fila de TrainingAccess existente pero no
 * vigente — expirada o revocada).
 *
 * IMPORTANTE (Bloque 5, documentado explícitamente a pedido del usuario):
 * este checkpoint responde una pregunta DISTINTA de
 * `HealthScreeningRequirement::isSatisfied()`. Ese requirement solo dice
 * si la CONVERSACIÓN de screening ya se cerró (se preguntó lo necesario);
 * este gate dice si TÉCNICAMENTE se puede generar la primera rutina. Es
 * perfectamente válido y esperado que `isSatisfied() === true` mientras
 * este método deniega con 'health_screening_pending' — el screening ya no
 * tiene preguntas pendientes, pero la declaración sigue esperando revisión
 * humana. Nunca deben confundirse ni fusionarse estos dos estados.
 */
class TrainingAccessGate
{
    public function authorize(Contact $contact): AccessGateResult
    {
        $profile = $contact->trainingProfile;

        if ($profile !== null && $profile->safety_status === SafetyStatus::FlaggedForReview) {
            return AccessGateResult::deny('safety_flagged');
        }

        // Bloque 5: solo bloquea ANTES de la primera rutina — mismo
        // criterio de "primera rutina" ya usado por
        // TrainingIntentClassifier (zero WorkoutSessions). Una vez que el
        // contacto ya tiene al menos una sesión, una declaración pendiente
        // posterior no revive este bloqueo (fuera de alcance de este
        // bloque — ver docs/DECISIONS.md D048).
        if ($contact->workoutSessions()->count() === 0
            && DeclaredHealthCondition::hasPendingReviewFor($contact->id)) {
            return AccessGateResult::deny('health_screening_pending');
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
