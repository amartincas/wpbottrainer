<?php

namespace App\Training\Onboarding\Requirements;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\QuestionContext;
use App\Training\Support\OnboardingConversationService;

/**
 * Bloque 4 — Capa 1 (bloqueante), mecanismo LEGACY TEMPORAL, no la solución
 * definitiva de seguridad. Sigue escribiendo `TrainingProfile.restrictions`
 * (texto libre) exactamente igual que hoy — invariante explícito de este
 * bloque: una condición de salud declarada (ver Bloque 2,
 * `DeclaredHealthConditionRecorder`) NUNCA equivale automáticamente a una
 * restricción funcional. El futuro `HealthScreeningRequirement` (bloque
 * posterior, no implementado aquí) usará `DeclaredHealthConditionRecorder` y
 * podrá, cuando corresponda, conducir a una `TrainingRestriction` explícita
 * — este Requirement no se convierte en esa pieza, solo mantiene el
 * comportamiento actual sin regresión mientras esa pieza no exista.
 */
class RestrictionsRequirement implements OnboardingRequirement
{
    public function key(): string
    {
        return 'restrictions';
    }

    public function extractedKeys(): array
    {
        return ['restrictions'];
    }

    public function isBlocking(TrainingProfile $profile, Contact $contact): bool
    {
        return true;
    }

    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool
    {
        return $profile->restrictions !== null;
    }

    public function validate(array $rawValues): array
    {
        return $rawValues;
    }

    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void
    {
        if (($validatedValues['restrictions'] ?? null) !== null) {
            $profile->update(['restrictions' => $validatedValues['restrictions']]);
        }
    }

    public function onAsked(Contact $contact, TrainingProfile $profile): void {}

    public function questionContext(TrainingProfile $profile, Contact $contact): QuestionContext
    {
        return new QuestionContext(
            key: $this->key(),
            purpose: 'identificar lesiones, dolor o limitaciones físicas relevantes antes de prescribir',
            blocking: $this->isBlocking($profile, $contact),
            expectedType: 'array',
            validOptions: null,
            knownContext: [],
            fallbackQuestion: OnboardingConversationService::fallbackQuestionFor($this->key()),
        );
    }
}
