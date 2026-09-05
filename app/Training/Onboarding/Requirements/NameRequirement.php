<?php

namespace App\Training\Onboarding\Requirements;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\QuestionContext;
use App\Training\Support\OnboardingConversationService;

/**
 * Bloque 4 — Capa 1 (bloqueante). El nombre vive en `Contact.customer_name`,
 * no en `TrainingProfile` (identidad, no dato de entrenamiento) — ver Hito 8.3.
 */
class NameRequirement implements OnboardingRequirement
{
    public function key(): string
    {
        return 'name';
    }

    public function extractedKeys(): array
    {
        return ['name'];
    }

    public function isBlocking(TrainingProfile $profile, Contact $contact): bool
    {
        return true;
    }

    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool
    {
        return $contact->customer_name !== null;
    }

    public function validate(array $rawValues): array
    {
        return $rawValues;
    }

    /**
     * Write-once: nunca sobrescribe un nombre ya guardado (hoy no hay
     * mecanismo de corrección explícita — ver TrainingHandler original).
     */
    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void
    {
        if (($validatedValues['name'] ?? null) !== null && $contact->customer_name === null) {
            $contact->update(['customer_name' => $validatedValues['name']]);
        }
    }

    public function onAsked(Contact $contact, TrainingProfile $profile): void
    {
        // No-op — sin semántica de "preguntado una sola vez" para este campo.
    }

    public function questionContext(TrainingProfile $profile, Contact $contact): QuestionContext
    {
        return new QuestionContext(
            key: $this->key(),
            purpose: 'saber cómo dirigirse a la persona durante toda la conversación',
            blocking: $this->isBlocking($profile, $contact),
            expectedType: 'string',
            validOptions: null,
            knownContext: [],
            fallbackQuestion: OnboardingConversationService::fallbackQuestionFor($this->key()),
        );
    }
}
