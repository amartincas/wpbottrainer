<?php

namespace App\Training\Support;

/**
 * Hito B3.1 — resultado puro de `PendingPreferenceClarificationResolver::resolve()`.
 * Envuelve, sin reinterpretarla, la `TrainingPreferenceIdentityResolution`
 * que produjo `TrainingPreferenceIdentityResolver::resolve()` — deliberadamente
 * NO se reutiliza esa clase directamente aquí: su contrato de 3 estados
 * (`resolved`/`clarify`/`unresolved`) es compartido con otros llamadores del
 * dominio B3 (ver `TrainingHandler::handleTrainingPreferenceMessage()`) que
 * no conocen el concepto de "pending"; mezclar un estado adicional ahí
 * contaminaría ese contrato. Este DTO es el propio de ESTE consumidor.
 */
final readonly class PendingClarificationOutcome
{
    private function __construct(
        public string $status,
        public ?TrainingPreferenceIdentityResolution $resolution = null,
    ) {}

    public static function resolved(TrainingPreferenceIdentityResolution $resolution): self
    {
        return new self('resolved', $resolution);
    }

    public static function ambiguous(TrainingPreferenceIdentityResolution $resolution): self
    {
        return new self('ambiguous', $resolution);
    }

    public static function noMatch(): self
    {
        return new self('no_match');
    }
}
