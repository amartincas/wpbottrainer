<?php

namespace App\ExerciseCatalog\Curation\Exceptions;

/**
 * Hito 9.3 (fix post-E2E) — se lanza cuando la IA no devolvió un JSON
 * válido con la forma esperada, o cuando el resultado viola una regla de
 * fidelidad verificable por código (ver
 * App\ExerciseCatalog\Curation\ExerciseSpanishContentGenerator::validate()).
 * Nunca se guarda un resultado parcial/sospechoso — el llamador (Filament)
 * muestra este mensaje y el `Exercise` queda exactamente como estaba.
 */
class SpanishContentGenerationException extends \RuntimeException {}
