<?php

namespace App\Training\Support;

use App\Training\Enums\PreferenceMessageCategory;

/**
 * Hito B3 (diseño v3 FINAL) — resultado puro de
 * `TrainingPreferenceMessageClassifier::classify()`. `category === null`
 * significa "ningún marcador de la gramática cerrada coincidió" (None) —
 * deliberadamente distinto de `Ambiguous` (que SÍ es un case del enum: algo
 * relevante se detectó, pero no se puede resolver sin más información).
 */
final readonly class TrainingPreferenceClassification
{
    public function __construct(
        public ?PreferenceMessageCategory $category,
        public ?string $safetySubcategory = null,
        public ?string $candidateTerm = null,
        public bool $noPuedoBare = false,
    ) {}

    public static function none(): self
    {
        return new self(null);
    }
}
