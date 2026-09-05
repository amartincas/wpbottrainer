<?php

namespace App\Training\Onboarding\Requirements;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\QuestionContext;
use App\Training\Support\OnboardingConversationService;

/**
 * Bloque 4 — Capa 1 (bloqueante). Corrige un bug real encontrado por
 * inspección: `TrainingProfile::firstMissingOnboardingField()` solo
 * comprobaba `available_equipment === null`, dejando a un usuario que
 * declaró "tengo de todo" (`equipment_fully_equipped=true`,
 * `available_equipment` queda `null` por diseño del extractor)
 * preguntándose por su equipo indefinidamente. `isSatisfied()` aquí
 * considera AMBAS señales.
 */
class EquipmentRequirement implements OnboardingRequirement
{
    public function key(): string
    {
        return 'available_equipment';
    }

    public function extractedKeys(): array
    {
        return ['available_equipment', 'equipment_fully_equipped'];
    }

    public function isBlocking(TrainingProfile $profile, Contact $contact): bool
    {
        return true;
    }

    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool
    {
        return $profile->equipment_fully_equipped === true || $profile->available_equipment !== null;
    }

    public function validate(array $rawValues): array
    {
        return $rawValues;
    }

    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void
    {
        $updates = [];

        if (($validatedValues['available_equipment'] ?? null) !== null) {
            $updates['available_equipment'] = $validatedValues['available_equipment'];
        }

        if (($validatedValues['equipment_fully_equipped'] ?? null) !== null) {
            $updates['equipment_fully_equipped'] = $validatedValues['equipment_fully_equipped'];
        }

        if ($updates !== []) {
            $profile->update($updates);
        }
    }

    public function onAsked(Contact $contact, TrainingProfile $profile): void {}

    public function questionContext(TrainingProfile $profile, Contact $contact): QuestionContext
    {
        return new QuestionContext(
            key: $this->key(),
            purpose: 'saber qué equipo hay disponible realmente, para no prescribir algo inaccesible',
            blocking: $this->isBlocking($profile, $contact),
            expectedType: 'compound', // available_equipment (array cerrado) + equipment_fully_equipped (bool)
            validOptions: null,
            knownContext: array_filter(['training_location' => $profile->training_location?->value]),
            fallbackQuestion: OnboardingConversationService::fallbackQuestionFor($this->key()),
        );
    }
}
