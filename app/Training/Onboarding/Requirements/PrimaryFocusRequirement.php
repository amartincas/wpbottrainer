<?php

namespace App\Training\Onboarding\Requirements;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\QuestionContext;
use App\Training\Support\OnboardingConversationService;

/**
 * Bloque 4 — Capa 2 (oportunista). Reclasificación DELIBERADA respecto al
 * código previo (donde bloqueaba el onboarding, Hito 8.4/D034) — instrucción
 * explícita de este bloque: "PrimaryFocus no debe bloquear la primera
 * rutina". Se sigue capturando en cualquier turno vía la extracción múltiple
 * ya existente; además, desde el turno 2 la política de turnos progresivos
 * (OnboardingRequirementRegistry::secondaryOpportunisticFor()) la invita
 * explícitamente como pregunta secundaria, sin exigir respuesta.
 */
class PrimaryFocusRequirement implements OnboardingRequirement
{
    public function key(): string
    {
        return 'primary_focus';
    }

    public function extractedKeys(): array
    {
        return ['primary_focus', 'secondary_focus'];
    }

    public function isBlocking(TrainingProfile $profile, Contact $contact): bool
    {
        return false;
    }

    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool
    {
        return $profile->primary_focus !== null;
    }

    public function validate(array $rawValues): array
    {
        return $rawValues;
    }

    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void
    {
        $updates = [];

        if (($validatedValues['primary_focus'] ?? null) !== null) {
            $updates['primary_focus'] = $validatedValues['primary_focus'];
        }

        if (($validatedValues['secondary_focus'] ?? null) !== null) {
            $updates['secondary_focus'] = $validatedValues['secondary_focus'];
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
            purpose: 'si hay alguna zona del cuerpo que quiera priorizar especialmente (ej. glúteos, piernas, espalda, abdomen)',
            blocking: $this->isBlocking($profile, $contact),
            expectedType: 'array',
            validOptions: null,
            knownContext: array_filter(['goal' => $profile->goal?->value]),
            fallbackQuestion: OnboardingConversationService::fallbackQuestionFor($this->key()),
        );
    }
}
