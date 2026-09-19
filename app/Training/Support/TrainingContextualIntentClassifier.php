<?php

namespace App\Training\Support;

use App\Core\Messaging\ContextualIntentClassifierInterface;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Intent;
use App\Models\Contact;
use App\Models\Reminder;
use App\Training\Enums\TrainingAccessStatus;
use App\Training\Enums\WorkoutSessionStatus;

/**
 * Precedencia de Intents (ver docs/DECISIONS.md) — extraído literalmente de
 * TrainingIntentClassifier: estas 4 reglas clasifican Intent::Training
 * ÚNICAMENTE por el estado del Contact, sin ninguna señal textual del
 * mensaje. Por eso viven en un classifier separado, marcado con
 * ContextualIntentClassifierInterface, registrado en el ÚLTIMO tier del
 * Router — así una señal EXPLÍCITA de otro dominio (Referral, Payment,
 * CustomerCare) siempre tiene oportunidad de clasificar primero, aunque
 * cualquiera de estas 4 condiciones esté activa.
 *
 * Ninguna condición, consulta ni significado cambió respecto a su versión
 * original dentro de TrainingIntentClassifier — extracción mecánica.
 */
class TrainingContextualIntentClassifier implements ContextualIntentClassifierInterface
{
    public function classify(ExecutionContext $context): ?Intent
    {
        $contact = Contact::where('tenant_id', $context->tenant->id)
            ->where('customer_phone', $context->message->from)
            ->first();

        if ($contact === null) {
            return null;
        }

        if ($this->hasIncompleteOnboarding($contact)
            || $this->hasPendingWorkoutSession($contact)
            || $this->hasActiveAccessAwaitingFirstWorkout($contact)
            || $this->hasAwaitingReminderResponse($contact)
        ) {
            return Intent::Training;
        }

        return null;
    }

    private function hasIncompleteOnboarding(Contact $contact): bool
    {
        $profile = $contact->trainingProfile;

        return $profile !== null && ! $profile->isOnboardingComplete($contact);
    }

    private function hasPendingWorkoutSession(Contact $contact): bool
    {
        return $contact->workoutSessions()
            ->where('status', WorkoutSessionStatus::Scheduled)
            ->exists();
    }

    /**
     * Hito 8.1 — hallazgo real del E2E comercial: un contacto con acceso
     * recién activado, sin ninguna WorkoutSession todavía, respondiendo a la
     * invitación a entrenar ("Sí", "Dale", "Listo") no contiene ninguna
     * palabra clave de Training ni cae en ninguna otra señal de estado — el
     * Router caía por defecto a fallback_chat (el chat genérico heredado de
     * ecommerce), que improvisaba una respuesta sin autoridad real.
     *
     * Deliberadamente acotada a este caso concreto (acceso activo + cero
     * WorkoutSession) — NO es un mecanismo general de "qué se espera del
     * usuario en cualquier estado conversacional futuro"; eso es una
     * responsabilidad de un futuro motor de Proactivity/estado
     * conversacional, no de este clasificador determinista. Ver
     * docs/DECISIONS.md.
     */
    private function hasActiveAccessAwaitingFirstWorkout(Contact $contact): bool
    {
        $access = $contact->trainingAccess;

        if ($access === null || $access->status !== TrainingAccessStatus::Active) {
            return false;
        }

        return ! $contact->workoutSessions()->exists();
    }

    /**
     * Hito 10 (D053) — mismo patrón que `hasActiveAccessAwaitingFirstWorkout()`:
     * una ventana corta y acotada, no un mecanismo general de "qué se espera
     * del usuario". Sin esto, la respuesta a un `Reminder` recién disparado
     * ("sí", "dale") podría perderse en `FallbackChatHandler` — misma clase
     * de brecha ya documentada para "sesión recién completada" (deuda de
     * Bloque 9), cerrada aquí para el caso de recordatorios.
     */
    private function hasAwaitingReminderResponse(Contact $contact): bool
    {
        return Reminder::where('contact_id', $contact->id)
            ->whereNotNull('awaiting_response_until')
            ->where('awaiting_response_until', '>', now())
            ->exists();
    }
}
