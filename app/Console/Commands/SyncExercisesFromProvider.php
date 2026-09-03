<?php

namespace App\Console\Commands;

use App\ExerciseCatalog\Importer\ExerciseImporter;
use App\ExerciseCatalog\ProviderRegistry;
use App\Training\Enums\MuscleFocus;
use Illuminate\Console\Command;

/**
 * Hito 9.1 — sincroniza METADATA de un proveedor (nunca video, nunca
 * activa ejercicios automáticamente). Sin argumento `--muscle`, trae todo
 * lo que el proveedor devuelva (catálogo completo, con y sin video) — usar
 * `--muscle` para acotar a un foco específico.
 *
 * Ejemplo: `php artisan exercises:sync ymove --muscle=glutes --muscle=chest`
 *
 * Hito 9.3 — reescrito sobre ExerciseImporter::fullSync(): antes usaba
 * importSearch(), que solo pedía UNA página por criterio — con un músculo
 * de más de ~20 ejercicios (la mayoría), eso desactivaba en masa
 * ejercicios reales que simplemente no cabían en esa página (defecto real
 * encontrado y corregido, nunca ejecutado en producción). fullSync()
 * pagina de verdad usando la paginación real del proveedor, y su
 * reconciliación de bajas queda acotada al mismo foco de músculo
 * solicitado — un `--muscle=quads` nunca puede tocar `is_active` de un
 * ejercicio de otro músculo.
 */
class SyncExercisesFromProvider extends Command
{
    protected $signature = 'exercises:sync {provider} {--muscle=* : Uno o más valores de MuscleFocus a sincronizar; sin ninguno, trae todo el catálogo}';

    protected $description = 'Sincroniza metadata de ejercicios de un proveedor externo (sin video, sin activar automáticamente).';

    public function handle(ProviderRegistry $registry, ExerciseImporter $importer): int
    {
        $providerKey = $this->argument('provider');

        if (! $registry->has($providerKey)) {
            $this->error("Proveedor desconocido: '{$providerKey}'. Conocidos: ".implode(', ', $registry->knownKeys()));

            return self::FAILURE;
        }

        $muscleOptions = $this->option('muscle');
        $focuses = [];

        foreach ($muscleOptions as $muscleValue) {
            $focus = MuscleFocus::tryFrom($muscleValue);

            if ($focus === null) {
                $this->error("Valor de --muscle desconocido: '{$muscleValue}'.");

                return self::FAILURE;
            }

            $focuses[] = $focus;
        }

        // Sin --muscle: una sola corrida sin filtro, sobre el catálogo completo.
        $runs = $focuses === [] ? [null] : $focuses;
        $anyFailed = false;

        foreach ($runs as $focus) {
            $result = $importer->fullSync($providerKey, muscleFocus: $focus);
            $label = $focus !== null ? " para foco '{$focus->value}'" : ' (catálogo completo)';

            if (! $result->completedFully) {
                $anyFailed = true;
                $this->error("Sincronización{$label} incompleta tras {$result->pagesProcessed} página(s): {$result->errorMessage}");
                $this->comment('No se reconciliaron bajas para esta corrida — una respuesta incompleta nunca se trata como ejercicio desaparecido.');

                continue;
            }

            $this->info(
                "Sincronización{$label}: {$result->totalReceived} recibidos ({$result->created} nuevos, {$result->updated} actualizados, {$result->unchanged} sin cambios) en {$result->pagesProcessed} página(s)."
            );

            if ($result->possiblyRemoved !== []) {
                $this->info(count($result->possiblyRemoved).' desactivados (ya no aparecen en el proveedor, nunca borrados): '.implode(', ', $result->possiblyRemoved));
            }
        }

        $this->comment('Recordatorio: ningún ejercicio nuevo queda activo automáticamente — requiere Exercise::activate() tras revisión humana de contraindications.');

        return $anyFailed ? self::FAILURE : self::SUCCESS;
    }
}
