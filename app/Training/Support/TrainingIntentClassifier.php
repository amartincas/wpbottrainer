<?php

namespace App\Training\Support;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Intent;
use App\Core\Messaging\IntentClassifierInterface;
use App\Models\Contact;
use App\Training\Enums\TrainingAccessStatus;
use App\Training\Enums\WorkoutSessionStatus;

/**
 * Deterministic classification of "does this message belong to Training?" —
 * no LLM call (Hito 5). A keyword match against the message text is cheap,
 * always-on, and correct enough for the first two intents that exist.
 * LLM-assisted classification (for phrasings the keyword list misses) is a
 * documented future enhancement, not built here — see docs/DECISIONS.md.
 *
 * A Contact already mid-onboarding (an existing but incomplete
 * TrainingProfile) is also routed to training even without a keyword match,
 * so answering an onboarding question ("3 veces por semana") continues the
 * flow instead of falling back to general conversation.
 *
 * Hito 6: the same reasoning extends to a Contact with a pending
 * WorkoutSession — a report like "Sentadilla 10x40" or "ya terminé" rarely
 * contains any of the keywords below, but it is unambiguously a Training
 * message once a workout was actually delivered and is awaiting a report.
 *
 * Works on already-transcribed text: Ingest (Core) transcribes audio into
 * IngestedMessage->messageBody before Router ever runs, so this classifier
 * needs no audio-specific handling of its own.
 */
class TrainingIntentClassifier implements IntentClassifierInterface
{
    private const KEYWORDS = [
        'entrenar', 'entrenamiento', 'entreno', 'ejercicio', 'ejercitarme',
        'ejercitar', 'rutina', 'gimnasio', 'gym', 'ponerme en forma',
        'plan de entrenamiento', 'bajar de peso', 'perder peso',
        'ganar musculo', 'ganar músculo', 'tonificar', 'quiero entrenar',
        'hacer ejercicio', 'ponerme fit', 'estar en forma',
    ];

    public function classify(ExecutionContext $context): ?Intent
    {
        $body = mb_strtolower($context->message->messageBody ?? '');

        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($body, $keyword)) {
                return Intent::Training;
            }
        }

        $contact = Contact::where('tenant_id', $context->tenant->id)
            ->where('customer_phone', $context->message->from)
            ->first();

        if ($contact === null) {
            return null;
        }

        if ($this->hasIncompleteOnboarding($contact)
            || $this->hasPendingWorkoutSession($contact)
            || $this->hasActiveAccessAwaitingFirstWorkout($contact)
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
}
