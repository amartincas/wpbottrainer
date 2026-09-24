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
 *
 * Corrección post-E2E real (hallazgo de `MAX_CLARIFICATION_OPTIONS`) —
 * `totalMatches`: cuántas coincidencias REALES encontró el catálogo antes
 * de recortar a `clarificationOptions` (como máximo
 * `TrainingPreferenceIdentityResolver::MAX_CLARIFICATION_OPTIONS`
 * elementos). `hasMoreMatches()` se DERIVA de ambos campos, nunca se
 * almacena aparte — evita que la señal "hay más" pueda desincronizarse del
 * conteo real o de la lista mostrada.
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
        public int $totalMatches = 0,
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
     * @param  array<int, string>  $options  Como máximo
     *         `TrainingPreferenceIdentityResolver::MAX_CLARIFICATION_OPTIONS`
     *         elementos — el recorte ya ocurrió en el resolver.
     * @param  int  $totalMatches  Cuántas coincidencias reales existían en
     *         el catálogo, ANTES del recorte — siempre >= count($options).
     */
    public static function clarify(array $options, int $totalMatches): self
    {
        return new self('clarify', clarificationOptions: $options, totalMatches: $totalMatches);
    }

    public static function unresolved(): self
    {
        return new self('unresolved');
    }

    /**
     * true cuando el catálogo tenía más coincidencias reales que las que
     * caben en `clarificationOptions` — TrainingHandler la usa para avisar
     * honestamente al usuario que la lista mostrada está incompleta, en vez
     * de truncarla en silencio (Sección 15 del diseño B3: nunca un
     * fallback silencioso).
     */
    public function hasMoreMatches(): bool
    {
        return $this->totalMatches > count($this->clarificationOptions);
    }
}
