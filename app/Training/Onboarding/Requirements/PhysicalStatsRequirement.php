<?php

namespace App\Training\Onboarding\Requirements;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\QuestionContext;
use App\Training\Support\OnboardingConversationService;

/**
 * Bloque 4 — Capa 2 (oportunista). Único requirement cuya satisfacción NO
 * depende de tener un valor, sino de haber sido preguntado una vez
 * (`physical_stats_asked`) — se acepta cualquier respuesta, parcial o
 * ninguna, y nunca se vuelve a insistir. `onAsked()` es lo que marca la
 * bandera, sin importar si `apply()` llegó a escribir algo.
 */
class PhysicalStatsRequirement implements OnboardingRequirement
{
    public function key(): string
    {
        return 'physical_stats';
    }

    public function extractedKeys(): array
    {
        return ['age', 'sex', 'weight_kg', 'height_cm'];
    }

    public function isBlocking(TrainingProfile $profile, Contact $contact): bool
    {
        return false;
    }

    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool
    {
        return $profile->physical_stats_asked === true;
    }

    public function validate(array $rawValues): array
    {
        return $rawValues;
    }

    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void
    {
        $updates = [];

        foreach (['age', 'sex', 'weight_kg', 'height_cm'] as $field) {
            if (($validatedValues[$field] ?? null) !== null) {
                $updates[$field] = $validatedValues[$field];
            }
        }

        if ($updates !== []) {
            $profile->update($updates);
        }
    }

    public function onAsked(Contact $contact, TrainingProfile $profile): void
    {
        if (! $profile->physical_stats_asked) {
            $profile->update(['physical_stats_asked' => true]);
        }
    }

    public function questionContext(TrainingProfile $profile, Contact $contact): QuestionContext
    {
        return new QuestionContext(
            key: $this->key(),
            purpose: 'afinar el plan con edad, sexo, peso y estatura — opcional, nunca obligatorio',
            blocking: $this->isBlocking($profile, $contact),
            expectedType: 'compound', // age/sex/weight_kg/height_cm, cada uno opcional
            validOptions: null,
            knownContext: [],
            fallbackQuestion: OnboardingConversationService::fallbackQuestionFor($this->key()),
        );
    }
}
