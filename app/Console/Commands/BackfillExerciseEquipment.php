<?php

namespace App\Console\Commands;

use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use Illuminate\Console\Command;

/**
 * Hito 9.3 (post-deploy) — backfill LOCAL, sin llamadas al proveedor: se
 * completó el vocabulario de equipamiento (Equipment, 13 valores nuevos) y
 * el mapa del Normalizer de YMove después de que 1068 ejercicios ya
 * estaban sincronizados con el mapa VIEJO (incompleto) — cualquiera de
 * esos 1068 cuyo `equipment` crudo cayera en uno de los 13 valores
 * faltantes quedó guardado con `equipment_needed=[]` ("sin equipo") de
 * forma incorrecta.
 *
 * Nunca llama a la API: el valor crudo de `equipment` ya vive en
 * `provider_metadata` de cada fila (guardado tal cual desde el sync
 * original) — este comando solo vuelve a pasar ese valor por el mapa ya
 * corregido del Normalizer y actualiza `equipment_needed` si cambió.
 *
 * Deliberadamente NO parte de `ExerciseProviderInterface` (es un arreglo
 * puntual de datos ya sincronizados, no una operación de catálogo que
 * todo proveedor deba soportar) — se apoya en que el Normalizer resuelto
 * exponga `mapEquipment(?string): array` (hoy solo `YMoveExerciseNormalizer`
 * lo hace); si un proveedor no lo expone, se informa y no se toca nada.
 */
class BackfillExerciseEquipment extends Command
{
    protected $signature = 'exercises:backfill-equipment {provider=ymove}';

    protected $description = 'Re-deriva equipment_needed de ejercicios ya sincronizados usando el mapa de equipamiento actual (sin llamar al proveedor).';

    public function handle(ProviderRegistry $registry): int
    {
        $providerKey = $this->argument('provider');

        if (! $registry->has($providerKey)) {
            $this->error("Proveedor desconocido: '{$providerKey}'. Conocidos: ".implode(', ', $registry->knownKeys()));

            return self::FAILURE;
        }

        $normalizer = $registry->getNormalizer($providerKey);

        if (! method_exists($normalizer, 'mapEquipment')) {
            $this->error("El normalizer de '{$providerKey}' no expone mapEquipment() — este backfill no aplica a este proveedor.");

            return self::FAILURE;
        }

        $exercises = Exercise::where('provider', $providerKey)->get();
        $changed = 0;

        foreach ($exercises as $exercise) {
            $rawEquipment = $exercise->provider_metadata['equipment'] ?? null;
            $recomputed = $normalizer->mapEquipment($rawEquipment);

            if ($recomputed !== ($exercise->equipment_needed ?? [])) {
                $exercise->update(['equipment_needed' => $recomputed]);
                $changed++;
            }
        }

        $this->info("{$changed} de {$exercises->count()} ejercicios de '{$providerKey}' actualizados (equipment_needed re-derivado localmente, cero llamadas al proveedor).");

        return self::SUCCESS;
    }
}
