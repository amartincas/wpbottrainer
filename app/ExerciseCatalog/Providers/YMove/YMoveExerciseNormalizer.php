<?php

namespace App\ExerciseCatalog\Providers\YMove;

use App\ExerciseCatalog\Contracts\ExerciseNormalizerInterface;
use App\ExerciseCatalog\DTOs\NormalizedExerciseData;
use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\Training\Enums\Equipment;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\TrackingType;

/**
 * Hito 9.1 — traduce el shape crudo de YMove al vocabulario cerrado de
 * WpbotTrainer. Único lugar que sabe que YMove llama "muscleGroup" a lo
 * que nosotros llamamos `primary_muscle`, o que su equipo es un string
 * único en vez de un array.
 *
 * Auditoría real (prueba técnica Hito 8.4/9) de los campos que YMove
 * devuelve: id, title, slug, description, instructions[], importantPoints[],
 * muscleGroup, secondaryMuscles[]|null, equipment (string), category,
 * difficulty|null, videoDurationSecs|null, hasVideo*, exerciseType[],
 * videoUrl, videoHlsUrl, thumbnailUrl, thumbnails{}, videos[].
 *
 * YMove NO devuelve `contraindications` en absoluto — se deja
 * deliberadamente en null (nunca inventado), lo que además bloquea
 * `Exercise::activate()` hasta revisión humana real (ver docs/DECISIONS.md).
 * YMove tampoco devuelve un `movement_pattern` explícito — se deja en
 * null en vez de adivinar (mismo criterio, sin heurística confiable).
 */
class YMoveExerciseNormalizer implements ExerciseNormalizerInterface
{
    private const MUSCLE_GROUP_MAP = [
        'glutes' => MuscleFocus::Glutes,
        'quads' => MuscleFocus::Quads,
        'hamstrings' => MuscleFocus::Hamstrings,
        'calves' => MuscleFocus::Calves,
        'chest' => MuscleFocus::Chest,
        'back' => MuscleFocus::Back,
        'shoulders' => MuscleFocus::Shoulders,
        'biceps' => MuscleFocus::Biceps,
        'triceps' => MuscleFocus::Triceps,
        'abs' => MuscleFocus::Abs,
        'full_body' => MuscleFocus::FullBody,
    ];

    /**
     * El `muscle_group` GRUESO ya existente (Exercise.muscle_group, 6
     * valores, base de la rotación por continuidad de TrainingEngine —
     * sin cambios desde Hito 8.4) se deriva del mismo `muscleGroup` fino
     * de YMove, con un mapeo SEPARADO — mismo criterio dual ya establecido.
     */
    private const MUSCLE_GROUP_COARSE_MAP = [
        'glutes' => 'legs', 'quads' => 'legs', 'hamstrings' => 'legs', 'calves' => 'legs',
        'biceps' => 'arms', 'triceps' => 'arms',
        'abs' => 'core',
        'chest' => 'chest', 'back' => 'back', 'shoulders' => 'shoulders',
    ];

    /**
     * Hito 9.3 (post-deploy, corrección) — completo contra el vocabulario
     * OFICIAL y exhaustivo de YMove (`GET /exercises/equipment`, endpoint
     * de metadata sin costo de cuota, 22 valores reales confirmados en
     * vivo). Antes de esta corrección solo cubría 11 — cualquier ejercicio
     * con uno de los otros 11 caía silenciosamente en `equipment_needed=[]`
     * ("sin equipo") sin que nada lo señalara. `pull-up bar` de YMove usa
     * guion, se preserva tal cual apareció en la auditoría original.
     *
     * @var array<string, Equipment[]>
     */
    private const EQUIPMENT_MAP = [
        'bodyweight' => [],
        'barbell' => [Equipment::Barbell],
        'dumbbell' => [Equipment::Dumbbells],
        'dumbbells' => [Equipment::Dumbbells],
        'kettlebell' => [Equipment::Kettlebell],
        'cable' => [Equipment::CableMachine],
        'machine' => [Equipment::Machine],
        'band' => [Equipment::ResistanceBands],
        'bands' => [Equipment::ResistanceBands],
        'bench' => [Equipment::Bench],
        'pull-up bar' => [Equipment::PullUpBar],
        'medicine ball' => [Equipment::MedicineBall],
        'mat' => [Equipment::Mat],
        'chair' => [Equipment::Chair],
        'box' => [Equipment::Box],
        'weighted vest' => [Equipment::WeightedVest],
        'smith machine' => [Equipment::SmithMachine],
        'stability ball' => [Equipment::StabilityBall],
        'wall' => [Equipment::Wall],
        'cone' => [Equipment::Cone],
        'free weights' => [Equipment::FreeWeights],
        'landmine' => [Equipment::Landmine],
        'foam roller' => [Equipment::FoamRoller],
        'step' => [Equipment::Step],
        'towel' => [Equipment::Towel],
    ];

    /**
     * `tracking_type` es la ÚNICA excepción a "nunca inventar": la columna
     * no admite null (siempre existió con default `reps_and_load`, ver
     * migración de Hito 9.1) y YMove no distingue explícitamente
     * "por repeticiones" de "por tiempo". Heurística best-effort por
     * palabra clave, documentada como tal — cualquier ejercicio así
     * derivado sigue sujeto a revisión humana antes de `activate()`.
     */
    private const TIME_BASED_KEYWORDS = ['stretch', 'pose', 'hold', 'plank', 'mobility', 'isometric'];

    public function normalize(ProviderExerciseData $raw): NormalizedExerciseData
    {
        $data = $raw->raw;

        $muscleGroupKey = $data['muscleGroup'] ?? null;
        $primaryMuscle = $muscleGroupKey !== null ? (self::MUSCLE_GROUP_MAP[$muscleGroupKey] ?? null) : null;

        $secondaryMuscles = collect($data['secondaryMuscles'] ?? [])
            ->map(fn ($m) => self::MUSCLE_GROUP_MAP[$m] ?? null)
            ->filter()
            ->values()
            ->all();

        $muscleGroupCoarse = self::MUSCLE_GROUP_COARSE_MAP[$muscleGroupKey] ?? ($muscleGroupKey ?? 'core');

        $equipmentNeeded = $this->mapEquipment($data['equipment'] ?? null);

        // Pass-through directo — YMove ya devolvió `difficulty: null` en la
        // prueba técnica real (Barbell Hip Thrust); nunca se infiere.
        $difficulty = isset($data['difficulty']) && $data['difficulty'] !== null
            ? ExperienceLevel::tryFrom($data['difficulty'])
            : null;

        return new NormalizedExerciseData(
            provider: 'ymove',
            providerExerciseId: $raw->providerExerciseId,
            name: $data['title'] ?? "(ejercicio {$raw->providerExerciseId})",
            description: $data['description'] ?? null,
            instructions: array_values(array_filter($data['instructions'] ?? [], 'is_string')),
            importantPoints: array_values(array_filter($data['importantPoints'] ?? [], 'is_string')),
            primaryMuscle: $primaryMuscle,
            secondaryMuscles: $secondaryMuscles,
            muscleGroupCoarse: $muscleGroupCoarse,
            movementPattern: null,
            difficultyLevel: $difficulty,
            equipmentNeeded: $equipmentNeeded,
            trackingType: $this->inferTrackingType($data),
            // YMove no provee esto en absoluto (shape auditado) — nunca se
            // deriva de instructions/importantPoints. Solo existe si un
            // humano lo cura después.
            commonMistakes: [],
            breathingCue: null,
            exerciseType: array_values(array_filter($data['exerciseType'] ?? [], 'is_string')),
            videoDurationSeconds: $data['videoDurationSecs'] ?? null,
            rawMetadata: $data,
            hasVideo: isset($data['hasVideo']) ? (bool) $data['hasVideo'] : null,
        );
    }

    /**
     * Hito 9.3 (post-deploy) — extraído a método público (antes en línea
     * dentro de normalize()) para que un backfill local (ej.
     * `exercises:renormalize-equipment`) pueda re-derivar
     * `equipment_needed` de ejercicios YA sincronizados a partir del
     * `equipment` crudo que cada fila ya conserva en `provider_metadata`
     * — sin volver a llamar a YMove. Misma tabla, mismo resultado que
     * durante un sync real.
     *
     * @return array<int, string> valores de App\Training\Enums\Equipment
     */
    public function mapEquipment(?string $rawEquipment): array
    {
        $key = mb_strtolower(trim((string) $rawEquipment));

        return array_map(
            fn (Equipment $e) => $e->value,
            self::EQUIPMENT_MAP[$key] ?? []
        );
    }

    private function inferTrackingType(array $data): TrackingType
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $data['title'] ?? '',
            $data['category'] ?? '',
            implode(' ', $data['exerciseType'] ?? []),
        ])));

        foreach (self::TIME_BASED_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return TrackingType::TimeBased;
            }
        }

        return TrackingType::RepsAndLoad;
    }
}
