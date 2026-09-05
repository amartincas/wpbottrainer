<?php

namespace App\Training\Onboarding\Requirements;

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\QuestionContext;
use App\Training\Support\OnboardingConversationService;

/**
 * Bloque 4 — Capa 1 (bloqueante), mecanismo LEGACY TEMPORAL, no la solución
 * definitiva de seguridad. Escribía `TrainingProfile.restrictions` (texto
 * libre).
 *
 * DESACTIVADA desde el Bloque 5 (ver docs/DECISIONS.md D048):
 * `HealthScreeningRequirement` la reemplazó en el registro de
 * `AppServiceProvider` — una sola pregunta de screening, nunca dos
 * independientes sobre lo mismo. Esta clase se conserva SIN BORRAR, sin
 * modificar su lógica, únicamente como documentación histórica y por si
 * algún consumidor externo a este bloque todavía la instancia
 * directamente — pero ya NO recibe escrituras nuevas: `TrainingProfile.restrictions`
 * queda como mecanismo de SOLO LECTURA para `SafetyRestrictionResolver`
 * durante la transición.
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
