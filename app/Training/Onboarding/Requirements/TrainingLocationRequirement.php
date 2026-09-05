<?php

namespace App\Training\Onboarding\Requirements;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Enums\TrainingLocation;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\QuestionContext;
use App\Training\Support\OnboardingConversationService;

/** Bloque 4 — Capa 1 (bloqueante). */
class TrainingLocationRequirement implements OnboardingRequirement
{
    public function key(): string
    {
        return 'training_location';
    }

    public function extractedKeys(): array
    {
        return ['training_location'];
    }

    public function isBlocking(TrainingProfile $profile, Contact $contact): bool
    {
        return true;
    }

    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool
    {
        return $profile->training_location !== null;
    }

    public function validate(array $rawValues): array
    {
        return $rawValues;
    }

    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void
    {
        if (($validatedValues['training_location'] ?? null) !== null) {
            $profile->update(['training_location' => $validatedValues['training_location']]);
        }
    }

    public function onAsked(Contact $contact, TrainingProfile $profile): void {}

    public function questionContext(TrainingProfile $profile, Contact $contact): QuestionContext
    {
        return new QuestionContext(
            key: $this->key(),
            purpose: 'resolver la disponibilidad real de equipo — dónde entrena cambia qué es elegible',
            blocking: $this->isBlocking($profile, $contact),
            expectedType: 'enum',
            validOptions: array_map(fn (TrainingLocation $l) => $l->value, TrainingLocation::cases()),
            knownContext: array_filter(['goal' => $profile->goal?->value]),
            fallbackQuestion: OnboardingConversationService::fallbackQuestionFor($this->key()),
        );
    }
}
