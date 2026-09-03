<?php

namespace App\ExerciseCatalog\Importer;

use App\ExerciseCatalog\DTOs\NormalizedExerciseData;
use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use App\Training\Enums\TrackingType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Hito 9.1 — único lugar que escribe filas de `exercises` a partir de
 * contenido de proveedor. Importa METADATA únicamente — nunca video (ver
 * App\ExerciseCatalog\MediaResolver, resuelto en caliente al enviar).
 *
 * Todo ejercicio NUEVO entra con `is_active=false` y
 * `contraindications=null` — nunca se activa automáticamente, sin importar
 * qué tan completos parezcan los demás datos (ver docs/DECISIONS.md).
 * Un re-sync de un ejercicio YA EXISTENTE nunca toca `is_active` ni
 * `contraindications` — preserva cualquier revisión humana ya hecha.
 *
 * Hito 9.2 — mismo criterio de preservación se extiende a
 * `common_mistakes`/`breathing_cue`: ningún proveedor auditado los provee
 * (siempre `[]`/`null` desde cualquier Normalizer), así que cualquier
 * valor real en esas columnas SIEMPRE vino de una curación humana — un
 * re-sync nunca los toca, ni siquiera al crear (se dejan explícitamente
 * en `null`, nunca se copian del DTO). `instructions`/`important_points`
 * SÍ vienen del proveedor y SÍ se refrescan en cada re-sync, igual que
 * `name`/`muscle_group`.
 */
class ExerciseImporter
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    public function importOne(string $providerKey, string $providerExerciseId): Exercise
    {
        $provider = $this->registry->get($providerKey);
        $raw = $provider->find($providerExerciseId);

        if ($raw === null) {
            throw new \RuntimeException(
                "El proveedor '{$providerKey}' no devolvió el ejercicio '{$providerExerciseId}'."
            );
        }

        return $this->upsert($this->registry->getNormalizer($providerKey)->normalize($raw));
    }

    /**
     * @return Collection<int, Exercise>
     */
    public function importSearch(string $providerKey, ProviderSearchCriteria $criteria): Collection
    {
        $provider = $this->registry->get($providerKey);
        $normalizer = $this->registry->getNormalizer($providerKey);

        return $provider->search($criteria)
            ->map(fn (ProviderExerciseData $raw) => $this->upsert($normalizer->normalize($raw)))
            ->values();
    }

    private function upsert(NormalizedExerciseData $data): Exercise
    {
        $existing = Exercise::query()
            ->where('provider', $data->provider)
            ->where('provider_exercise_id', $data->providerExerciseId)
            ->first();

        $attributes = [
            'name' => $data->name,
            'description' => $data->description,
            'instructions' => $data->instructions,
            'important_points' => $data->importantPoints !== [] ? $data->importantPoints : null,
            'muscle_group' => $data->muscleGroupCoarse,
            'primary_muscle' => $data->primaryMuscle,
            'secondary_muscles' => $data->secondaryMuscles !== []
                ? array_map(fn ($m) => $m->value, $data->secondaryMuscles)
                : null,
            'movement_pattern' => $data->movementPattern,
            'equipment_needed' => $data->equipmentNeeded,
            'difficulty_level' => $data->difficultyLevel?->value,
            'tracking_type' => $data->trackingType ?? TrackingType::RepsAndLoad,
            'exercise_type' => $data->exerciseType,
            'video_duration_seconds' => $data->videoDurationSeconds,
            'provider' => $data->provider,
            'provider_exercise_id' => $data->providerExerciseId,
            'provider_metadata' => $data->rawMetadata,
            'synced_at' => now(),
        ];

        if ($existing === null) {
            $attributes['slug'] = Str::slug($data->name).'-'.Str::random(6);
            // Nunca activo, nunca con contraindicaciones asumidas — ver
            // docs/DECISIONS.md. Único camino a is_active=true es
            // Exercise::activate(), después de revisión humana real.
            $attributes['contraindications'] = null;
            $attributes['is_active'] = false;
            // common_mistakes/breathing_cue NUNCA vienen del proveedor —
            // deliberadamente excluidos del DTO aquí, incluso al crear.
            // Solo una curación humana posterior los completa.
            $attributes['common_mistakes'] = null;
            $attributes['breathing_cue'] = null;

            return Exercise::create($attributes);
        }

        // Re-sync: common_mistakes/breathing_cue NUNCA se incluyen aquí —
        // preserva cualquier curación humana existente, exactamente igual
        // que is_active/contraindications.
        $existing->update($attributes);

        return $existing->fresh();
    }
}
