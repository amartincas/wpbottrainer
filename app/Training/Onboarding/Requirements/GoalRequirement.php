<?php

namespace App\Training\Onboarding\Requirements;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Enums\TrainingGoal;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\QuestionContext;
use App\Training\Support\OnboardingConversationService;

/** Bloque 4 — Capa 1 (bloqueante). */
class GoalRequirement implements OnboardingRequirement
{
    public function key(): string
    {
        return 'goal';
    }

    public function extractedKeys(): array
    {
        return ['goal'];
    }

    public function isBlocking(TrainingProfile $profile, Contact $contact): bool
    {
        return true;
    }

    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool
    {
        return $profile->goal !== null;
    }

    public function validate(array $rawValues): array
    {
        return $rawValues;
    }

    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void
    {
        if (($validatedValues['goal'] ?? null) !== null) {
            $profile->update(['goal' => $validatedValues['goal']]);
        }
    }

    public function onAsked(Contact $contact, TrainingProfile $profile): void {}

    public function questionContext(TrainingProfile $profile, Contact $contact): QuestionContext
    {
        return new QuestionContext(
            key: $this->key(),
            purpose: 'definir el objetivo general de entrenamiento, base de toda la prescripción',
            blocking: $this->isBlocking($profile, $contact),
            expectedType: 'enum',
            validOptions: array_map(fn (TrainingGoal $g) => $g->value, TrainingGoal::cases()),
            knownContext: [],
            fallbackQuestion: OnboardingConversationService::fallbackQuestionFor($this->key()),
        );
    }
}
