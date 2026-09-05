<?php

namespace App\Training\Onboarding\Requirements;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Enums\ExperienceLevel;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\QuestionContext;
use App\Training\Support\OnboardingConversationService;

/** Bloque 4 — Capa 1 (bloqueante). */
class ExperienceLevelRequirement implements OnboardingRequirement
{
    public function key(): string
    {
        return 'experience_level';
    }

    public function extractedKeys(): array
    {
        return ['experience_level'];
    }

    public function isBlocking(TrainingProfile $profile, Contact $contact): bool
    {
        return true;
    }

    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool
    {
        return $profile->experience_level !== null;
    }

    public function validate(array $rawValues): array
    {
        return $rawValues;
    }

    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void
    {
        if (($validatedValues['experience_level'] ?? null) !== null) {
            $profile->update(['experience_level' => $validatedValues['experience_level']]);
        }
    }

    public function onAsked(Contact $contact, TrainingProfile $profile): void {}

    public function questionContext(TrainingProfile $profile, Contact $contact): QuestionContext
    {
        return new QuestionContext(
            key: $this->key(),
            purpose: 'ajustar la intensidad y complejidad de los ejercicios al punto de partida real',
            blocking: $this->isBlocking($profile, $contact),
            expectedType: 'enum',
            validOptions: array_map(fn (ExperienceLevel $e) => $e->value, ExperienceLevel::cases()),
            knownContext: array_filter([
                'goal' => $profile->goal?->value,
                'training_location' => $profile->training_location?->value,
            ]),
            fallbackQuestion: OnboardingConversationService::fallbackQuestionFor($this->key()),
        );
    }
}
