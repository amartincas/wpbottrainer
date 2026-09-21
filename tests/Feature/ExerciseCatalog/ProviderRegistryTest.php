<?php

use App\ExerciseCatalog\Contracts\ExerciseNormalizerInterface;
use App\ExerciseCatalog\Contracts\ExerciseProviderInterface;
use App\ExerciseCatalog\DTOs\NormalizedExerciseData;
use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\DTOs\ProviderSearchPage;
use App\ExerciseCatalog\DTOs\ResolvedMedia;
use App\ExerciseCatalog\Enums\MediaVariant;
use App\ExerciseCatalog\Importer\ExerciseImporter;
use App\ExerciseCatalog\ProviderRegistry;
use App\ExerciseCatalog\Providers\NullExerciseProvider;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseNormalizer;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseProvider;
use App\Models\Exercise;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\TrackingType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * Hito 9.1: `provider` es un string simple validado por este registro
 * contra config('exercise_providers.*') — nunca un enum de dominio ni una
 * tabla `providers`. Agregar un proveedor nuevo es una entrada de config +
 * una implementación, verificado aquí indirectamente por el hecho de que
 * NullExerciseProvider satisface el mismo contrato sin estar registrado.
 */
it('resolves the real ymove provider registered in config', function () {
    $registry = new ProviderRegistry;

    expect($registry->has('ymove'))->toBeTrue();
    expect($registry->get('ymove'))->toBeInstanceOf(YMoveExerciseProvider::class);
    expect($registry->getNormalizer('ymove'))->toBeInstanceOf(YMoveExerciseNormalizer::class);
    expect($registry->knownKeys())->toContain('ymove');
});

it('throws for an unknown provider key instead of silently returning null', function () {
    $registry = new ProviderRegistry;

    expect($registry->has('musclewiki'))->toBeFalse();
    expect(fn () => $registry->get('musclewiki'))->toThrow(InvalidArgumentException::class);
});

/**
 * Prueba de que el contrato no tiene fugas hacia YMove: un segundo
 * implementador trivial, sin registrar en config, satisface la misma
 * interfaz completa sin conocer nada de YMove.
 */
it('lets a second, completely unrelated provider implementation satisfy the same contract', function () {
    $provider = new NullExerciseProvider;

    expect($provider->key())->toBe('null');
    expect($provider->search(new ProviderSearchCriteria))->toHaveCount(0);
    expect($provider->find('anything'))->toBeNull();
    expect($provider->resolveMedia('anything', MediaVariant::Default))->toBeNull();
    expect($provider->isAvailable())->toBeFalse();
    expect($provider->variants('anything'))->toBe([]);
    expect($provider->alternatives('anything'))->toBe([]);
});

/**
 * Hito Provider-Agnostic Normalization — el test anterior prueba solo el
 * contrato de PROVIDER (fetch). Este prueba la cadena COMPLETA:
 * un segundo proveedor + su propio normalizer, registrados en
 * ProviderRegistry, importados con ExerciseImporter::importOne() REAL
 * (la misma clase que usa YMove, sin ninguna rama condicional por
 * proveedor), creando una fila de Exercise real — demuestra en código,
 * no solo por inspección, que agregar un proveedor nuevo es "una entrada
 * de config + dos clases", cero cambios en Exercise/ExerciseImporter/
 * TrainingEngine.
 */
it('lets a second provider+normalizer pair flow through ExerciseImporter and create a real Exercise, with zero changes to core classes', function () {
    Config::set('exercise_providers.providers.testprovider2', FakeSecondProvider::class);
    Config::set('exercise_providers.normalizers.testprovider2', FakeSecondNormalizer::class);

    $registry = new ProviderRegistry;
    expect($registry->has('testprovider2'))->toBeTrue();

    $importer = app(ExerciseImporter::class);
    $exercise = $importer->importOne('testprovider2', 'fake-id-1');

    expect($exercise)->toBeInstanceOf(Exercise::class);
    expect($exercise->provider)->toBe('testprovider2');
    expect($exercise->provider_exercise_id)->toBe('fake-id-1');
    expect($exercise->name)->toBe('Fake Exercise From Provider B');
    expect($exercise->equipment_needed)->toBe(['dumbbells']);
    expect($exercise->primary_muscle)->toBe(MuscleFocus::Chest);
    expect($exercise->is_active)->toBeFalse();
    expect($exercise->contraindications)->toBeNull();

    expect(Exercise::where('provider', 'testprovider2')->count())->toBe(1);
});

/**
 * Segundo implementador trivial de ExerciseProviderInterface, usado
 * SOLO por el test de arriba — vive en este archivo de test (no en
 * app/), exactamente como NullExerciseProvider prueba que el contrato de
 * PROVIDER no tiene fugas hacia YMove, este prueba lo mismo para la
 * cadena de IMPORTACIÓN completa.
 */
class FakeSecondProvider implements ExerciseProviderInterface
{
    public function key(): string
    {
        return 'testprovider2';
    }

    public function search(ProviderSearchCriteria $criteria): Collection
    {
        return collect();
    }

    public function find(string $providerExerciseId): ?ProviderExerciseData
    {
        return new ProviderExerciseData($providerExerciseId, [
            'name' => 'Fake Exercise From Provider B',
            'muscle' => 'chest',
            'gear' => 'dumbbells',
        ]);
    }

    public function searchPaged(ProviderSearchCriteria $criteria): ProviderSearchPage
    {
        return new ProviderSearchPage(items: collect(), page: 1, totalPages: 0, totalItems: 0);
    }

    public function resolveMedia(string $providerExerciseId, MediaVariant $variant = MediaVariant::Default): ?ResolvedMedia
    {
        return null;
    }

    public function isAvailable(): bool
    {
        return true;
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

/**
 * Su propio normalizer — vocabulario deliberadamente DISTINTO al de YMove
 * (`gear`/`muscle` en vez de `equipment`/`muscleGroup`) para demostrar que
 * NormalizedExerciseData es realmente el único punto de contacto con el
 * dominio; el shape crudo puede ser cualquier cosa.
 */
class FakeSecondNormalizer implements ExerciseNormalizerInterface
{
    public function normalize(ProviderExerciseData $raw): NormalizedExerciseData
    {
        return new NormalizedExerciseData(
            provider: 'testprovider2',
            providerExerciseId: $raw->providerExerciseId,
            name: $raw->raw['name'],
            description: null,
            instructions: ['Do the thing.'],
            importantPoints: [],
            primaryMuscle: MuscleFocus::Chest,
            secondaryMuscles: [],
            muscleGroupCoarse: 'chest',
            movementPattern: null,
            difficultyLevel: null,
            equipmentNeeded: ['dumbbells'],
            trackingType: TrackingType::RepsAndLoad,
            commonMistakes: [],
            breathingCue: null,
            exerciseType: [],
            videoDurationSeconds: null,
            rawMetadata: $raw->raw,
            hasVideo: null,
        );
    }
}
