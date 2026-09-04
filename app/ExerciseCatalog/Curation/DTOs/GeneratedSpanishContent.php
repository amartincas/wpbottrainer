<?php

namespace App\ExerciseCatalog\Curation\DTOs;

/**
 * Hito 9.3 (fix post-E2E) — resultado ya validado (nunca crudo) de
 * ExerciseSpanishContentGenerator::generate(). `instructions`/
 * `importantPoints` son siempre arrays de strings, con exactamente el
 * mismo número de elementos que el contenido original — ver la regla de
 * fidelidad estructural en ExerciseSpanishContentGenerator::validate().
 */
final readonly class GeneratedSpanishContent
{
    /**
     * @param  array<int, string>  $instructions
     * @param  array<int, string>  $importantPoints
     */
    public function __construct(
        public string $name,
        public array $instructions,
        public array $importantPoints,
    ) {}
}
