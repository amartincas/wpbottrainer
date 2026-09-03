<?php

namespace App\ExerciseCatalog\Providers;

use App\ExerciseCatalog\Contracts\ExerciseProviderInterface;
use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\DTOs\ResolvedMedia;
use App\ExerciseCatalog\Enums\MediaVariant;
use Illuminate\Support\Collection;

/**
 * Hito 9.1 — segundo implementador del contrato, presente desde el día 1
 * junto con YMoveExerciseProvider (no como una tarea posterior). Su único
 * propósito es demostrar, en tests, que ExerciseProviderInterface puede
 * implementarse sin conocer YMove en absoluto — si el contrato solo
 * pudiera expresarse pensando en YMove, esta clase lo revelaría de
 * inmediato. Nunca se usa en producción ni se registra en
 * config/exercise_providers.php.
 */
class NullExerciseProvider implements ExerciseProviderInterface
{
    public function key(): string
    {
        return 'null';
    }

    public function search(ProviderSearchCriteria $criteria): Collection
    {
        return collect();
    }

    public function find(string $providerExerciseId): ?ProviderExerciseData
    {
        return null;
    }

    public function resolveMedia(string $providerExerciseId, MediaVariant $variant = MediaVariant::Default): ?ResolvedMedia
    {
        return null;
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function variants(string $providerExerciseId): array
    {
        return [];
    }

    public function alternatives(string $providerExerciseId): array
    {
        return [];
    }
}
