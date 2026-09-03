<?php

namespace App\ExerciseCatalog\DTOs;

use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MovementPattern;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\TrackingType;

/**
 * Hito 9.1 — salida de ExerciseNormalizerInterface::normalize(): 100%
 * vocabulario propio de WpbotTrainer (enums de App\Training\Enums), cero
 * concepto del proveedor de origen. Es lo único que
 * App\ExerciseCatalog\Importer\ExerciseImporter lee para escribir un
 * Exercise — nunca lee `ProviderExerciseData::$raw` directamente.
 *
 * `difficultyLevel`/`movementPattern`/`trackingType` pueden venir `null` —
 * NUNCA se inventan cuando el proveedor no los informa (ej. YMove
 * devolvió `difficulty: null` en la prueba técnica real). `trackingType`
 * es la única excepción con un heurístico best-effort documentado (ver
 * YMoveExerciseNormalizer) porque la columna no admite null — cualquier
 * ejercicio así derivado queda igualmente sujeto a revisión humana antes
 * de activarse.
 *
 * Hito 9.1/9.2 — técnica de ejecución: `instructions`/`importantPoints`
 * vienen del proveedor (se refrescan en cada re-sync, igual que `name`).
 * `commonMistakes`/`breathingCue` NINGÚN proveedor auditado los provee —
 * siempre `[]`/`null` desde un Normalizer; solo existen si un humano los
 * cura después (`App\ExerciseCatalog\Importer\ExerciseImporter` nunca los
 * toca en un re-sync, ver docs/DECISIONS.md).
 *
 * Hito 9.3 (sincronización completa) — `hasVideo`: señal de disponibilidad
 * de video que el proveedor reporta en modo browse (sin costo de cuota,
 * distinta de si YA resolvimos un video real vía MediaResolver). Se
 * refresca en cada re-sync como cualquier otro campo de metadata —
 * `null` si el proveedor no informa el concepto.
 */
final readonly class NormalizedExerciseData
{
    /**
     * @param  string[]  $instructions
     * @param  string[]  $importantPoints
     * @param  MuscleFocus[]  $secondaryMuscles
     * @param  string[]  $equipmentNeeded  valores de App\Training\Enums\Equipment
     * @param  string[]  $commonMistakes
     * @param  string[]  $exerciseType
     * @param  array<string, mixed>  $rawMetadata
     */
    public function __construct(
        public string $provider,
        public string $providerExerciseId,
        public string $name,
        public ?string $description,
        public array $instructions,
        public array $importantPoints,
        public ?MuscleFocus $primaryMuscle,
        public array $secondaryMuscles,
        public string $muscleGroupCoarse,
        public ?MovementPattern $movementPattern,
        public ?ExperienceLevel $difficultyLevel,
        public array $equipmentNeeded,
        public ?TrackingType $trackingType,
        public array $commonMistakes,
        public ?string $breathingCue,
        public array $exerciseType,
        public ?int $videoDurationSeconds,
        public array $rawMetadata,
        public ?bool $hasVideo = null,
    ) {}
}
