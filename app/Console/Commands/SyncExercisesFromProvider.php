<?php

namespace App\Console\Commands;

use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\Importer\ExerciseImporter;
use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use App\Training\Enums\MuscleFocus;
use Illuminate\Console\Command;

/**
 * Hito 9.1 — sincroniza METADATA de un proveedor (nunca video, nunca
 * activa ejercicios automáticamente). Sin argumento `--muscle`, trae todo
 * lo que el proveedor devuelva para la cuenta configurada — usar
 * `--muscle` para respetar la matriz estratégica de cobertura y el límite
 * de la cuenta de prueba en vez de intentar traer el catálogo completo
 * (ver docs/DECISIONS.md, Hito 9, punto 10).
 *
 * Ejemplo: `php artisan exercises:sync ymove --muscle=glutes --muscle=chest`
 */
class SyncExercisesFromProvider extends Command
{
    protected $signature = 'exercises:sync {provider} {--muscle=* : Uno o más valores de MuscleFocus a sincronizar; sin ninguno, trae todo}';

    protected $description = 'Sincroniza metadata de ejercicios de un proveedor externo (sin video, sin activar automáticamente).';

    public function handle(ProviderRegistry $registry, ExerciseImporter $importer): int
    {
        $providerKey = $this->argument('provider');

        if (! $registry->has($providerKey)) {
            $this->error("Proveedor desconocido: '{$providerKey}'. Conocidos: ".implode(', ', $registry->knownKeys()));

            return self::FAILURE;
        }

        $muscleOptions = $this->option('muscle');
        $criteriaList = [];

        foreach ($muscleOptions as $muscleValue) {
            $focus = MuscleFocus::tryFrom($muscleValue);

            if ($focus === null) {
                $this->error("Valor de --muscle desconocido: '{$muscleValue}'.");

                return self::FAILURE;
            }

            $criteriaList[] = new ProviderSearchCriteria(muscleFocus: $focus);
        }

        if ($criteriaList === []) {
            $criteriaList[] = new ProviderSearchCriteria;
        }

        $touchedIds = [];

        foreach ($criteriaList as $criteria) {
            $imported = $importer->importSearch($providerKey, $criteria);

            foreach ($imported as $exercise) {
                $touchedIds[] = $exercise->provider_exercise_id;
            }

            $this->info("Sincronizados {$imported->count()} ejercicios".($criteria->muscleFocus !== null ? " para foco '{$criteria->muscleFocus->value}'" : '').'.');
        }

        // Baja suave: un provider_exercise_id que ya no aparece en esta
        // corrida se desactiva — nunca se borra físicamente (preserva la
        // integridad de WorkoutExercise.exercise_snapshot histórico).
        $deactivated = Exercise::query()
            ->where('provider', $providerKey)
            ->whereNotIn('provider_exercise_id', $touchedIds)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $this->info(
            'Total: '.count(array_unique($touchedIds))." ejercicios tocados. {$deactivated} desactivados (ya no aparecen en el proveedor)."
        );
        $this->comment('Recordatorio: ningún ejercicio nuevo queda activo automáticamente — requiere Exercise::activate() tras revisión humana de contraindications.');

        return self::SUCCESS;
    }
}
