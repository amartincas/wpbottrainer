<?php

namespace App\Training\Support;

use App\Training\Enums\PreferenceDimension;

/**
 * Hito B3 (diseño v3 FINAL, Sección A.4) — resultado de
 * `TrainingPreferenceIdentityResolver::resolve()`. Tres estados posibles,
 * nunca una aproximación intermedia:
 * - `resolved`: identidad única e inequívoca — seguro para persistir.
 * - `clarify`: hay candidatos, pero más de uno (o cero exactos con opciones
 *   por coincidencia parcial) — nunca se elige por el usuario.
 * - `unresolved`: no hay ningún candidato razonable que ofrecer.
 */
final readonly class TrainingPreferenceIdentityResolution
{
    /**
     * @param  array<int, string>  $clarificationOptions
     */
    private function __construct(
        public string $status,
        public ?PreferenceDimension $dimension = null,
        public ?int $exerciseId = null,
        public ?string $equipmentValue = null,
        public ?string $resolvedLabel = null,
        public array $clarificationOptions = [],
    ) {}

    public static function resolvedExercise(int $exerciseId, string $label): self
    {
        return new self('resolved', PreferenceDimension::Exercise, exerciseId: $exerciseId, resolvedLabel: $label);
    }

    public static function resolvedEquipment(string $equipmentValue, string $label): self
    {
        return new self('resolved', PreferenceDimension::Equipment, equipmentValue: $equipmentValue, resolvedLabel: $label);
    }

    /**
     * @param  array<int, string>  $options
     */
    public static function clarify(array $options = []): self
    {
        return new self('clarify', clarificationOptions: $options);
    }

    public static function unresolved(): self
    {
        return new self('unresolved');
    }
}
