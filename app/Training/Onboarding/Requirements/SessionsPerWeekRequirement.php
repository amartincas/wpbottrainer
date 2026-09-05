<?php

namespace App\Training\Onboarding\Requirements;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\QuestionContext;
use App\Training\Support\OnboardingConversationService;

/**
 * Bloque 4 — Capa 2 (oportunista). Reclasificación DELIBERADA respecto al
 * código previo (donde bloqueaba el onboarding) — instrucción explícita del
 * encargo original del Bloque 4 (CAPA 2 — OPORTUNISTAS/PROGRESIVOS incluye
 * SessionsPerWeek). Nunca bloquea la primera rutina.
 */
class SessionsPerWeekRequirement implements OnboardingRequirement
{
    public function key(): string
    {
        return 'sessions_per_week';
    }

    public function extractedKeys(): array
    {
        return ['sessions_per_week'];
    }

    public function isBlocking(TrainingProfile $profile, Contact $contact): bool
    {
        return false;
    }

    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool
    {
        return $profile->sessions_per_week !== null;
    }

    public function validate(array $rawValues): array
    {
        return $rawValues;
    }

    /**
     * Hito 9.0 (preservado): sessions_per_week por sí solo no cambia nada en
     * TrainingEngine — su único efecto real es derivar split_type
     * determinísticamente, localizado aquí en vez de en TrainingHandler.
     */
    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void
    {
        $sessionsPerWeek = $validatedValues['sessions_per_week'] ?? null;

        if ($sessionsPerWeek === null) {
            return;
        }

        $profile->update([
            'sessions_per_week' => $sessionsPerWeek,
            'split_type' => TrainingProfile::deriveSplitTypeFromSessionsPerWeek($sessionsPerWeek),
        ]);
    }

    public function onAsked(Contact $contact, TrainingProfile $profile): void {}

    public function questionContext(TrainingProfile $profile, Contact $contact): QuestionContext
    {
        return new QuestionContext(
            key: $this->key(),
            purpose: 'ajustar cuántos grupos musculares rotar por semana',
            blocking: $this->isBlocking($profile, $contact),
            expectedType: 'int',
            validOptions: null,
            knownContext: [],
            fallbackQuestion: OnboardingConversationService::fallbackQuestionFor($this->key()),
        );
    }
}
