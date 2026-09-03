<?php

namespace App\ExerciseCatalog;

use App\ExerciseCatalog\Contracts\ExerciseNormalizerInterface;
use App\ExerciseCatalog\Contracts\ExerciseProviderInterface;

/**
 * Hito 9.1 — único punto donde una clave de proveedor (string simple,
 * nunca un enum de dominio ni una tabla `providers`) se resuelve a sus
 * implementaciones reales. Agregar un proveedor nuevo es una clase +
 * `Adapter`/`Normalizer` + una entrada en config/exercise_providers.php —
 * cero cambios en `Exercise`, `TrainingEngine`, ni migraciones.
 *
 * `Exercise.provider` se valida en escritura contra `has()` (ver
 * App\ExerciseCatalog\Importer\ExerciseImporter) — nunca contra un enum.
 */
class ProviderRegistry
{
    /** @var array<string, class-string<ExerciseProviderInterface>> */
    private array $providers;

    /** @var array<string, class-string<ExerciseNormalizerInterface>> */
    private array $normalizers;

    public function __construct()
    {
        $this->providers = config('exercise_providers.providers', []);
        $this->normalizers = config('exercise_providers.normalizers', []);
    }

    public function get(string $key): ExerciseProviderInterface
    {
        if (! $this->has($key)) {
            throw new \InvalidArgumentException("Proveedor de ejercicios desconocido: '{$key}'.");
        }

        return app($this->providers[$key]);
    }

    public function getNormalizer(string $key): ExerciseNormalizerInterface
    {
        if (! isset($this->normalizers[$key])) {
            throw new \InvalidArgumentException("Normalizador desconocido para el proveedor: '{$key}'.");
        }

        return app($this->normalizers[$key]);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->providers);
    }

    /** @return string[] */
    public function knownKeys(): array
    {
        return array_keys($this->providers);
    }
}
