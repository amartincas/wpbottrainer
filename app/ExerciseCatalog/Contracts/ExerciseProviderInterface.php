<?php

namespace App\ExerciseCatalog\Contracts;

use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\DTOs\ResolvedMedia;
use App\ExerciseCatalog\Enums\MediaVariant;
use Illuminate\Support\Collection;

/**
 * Hito 9.1 — contrato genérico de proveedor de contenido de ejercicios.
 * Deliberadamente SIN ningún concepto exclusivo de YMove (ni de ningún
 * otro proveedor): ni sus nombres de campo, ni su formato de URL, ni su
 * vocabulario de músculos/equipamiento. Cualquier `TrainingEngine`,
 * `TrainingHandler` o `MediaResolver`-caller solo conoce esta interfaz —
 * nunca una clase concreta de proveedor.
 *
 * Verificado por App\ExerciseCatalog\Providers\NullExerciseProvider: un
 * segundo implementador trivial, presente desde el día 1, que prueba que
 * el contrato no tiene fugas hacia YMove sin necesitar esperar a un
 * segundo proveedor real.
 */
interface ExerciseProviderInterface
{
    /**
     * Clave estable de este proveedor (ej. 'ymove') — la misma que su
     * entrada en config('exercise_providers.providers'). Nunca se usa como
     * texto libre fuera de ese registro.
     */
    public function key(): string;

    /**
     * Búsqueda para SINCRONIZACIÓN del catálogo — nunca la invoca
     * TrainingEngine ni ningún Handler conversacional.
     *
     * @return Collection<int, ProviderExerciseData>
     */
    public function search(ProviderSearchCriteria $criteria): Collection;

    public function find(string $providerExerciseId): ?ProviderExerciseData;

    /**
     * URL fresca del recurso de video, resuelta en el momento — nunca debe
     * cachearse ni persistirse más allá de un único envío.
     */
    public function resolveMedia(string $providerExerciseId, MediaVariant $variant = MediaVariant::Default): ?ResolvedMedia;

    /**
     * Salud/cuota del proveedor — para decidir si puede usarse ahora mismo
     * (ej. antes de un sync manual, nunca en el camino caliente de envío).
     */
    public function isAvailable(): bool;

    /**
     * Variantes de un mismo ejercicio que el proveedor ofrece (ej. YMove:
     * fondo blanco / grabado en gimnasio). [] si el proveedor no distingue.
     *
     * @return string[]
     */
    public function variants(string $providerExerciseId): array;

    /**
     * Ejercicios equivalentes que el PROPIO proveedor sugiere. Opcional —
     * [] si no aplica. Nunca implica canonicalización cross-proveedor (ver
     * docs/DECISIONS.md, Hito 9: no se construye CanonicalExercise todavía).
     *
     * @return string[] provider_exercise_id de alternativas
     */
    public function alternatives(string $providerExerciseId): array;
}
