<?php

namespace App\Console\Commands;

use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use App\Training\Enums\Equipment;
use Illuminate\Console\Command;

/**
 * Hito Backfill controlado de Exercise Normalization — re-deriva
 * `equipment_needed`, `primary_muscle` y `secondary_muscles` de ejercicios
 * YA sincronizados, usando el normalizer ya corregido (Hito
 * Provider-Agnostic Normalization). Mismo mecanismo que
 * `BackfillExerciseEquipment` (cero llamadas al proveedor — el valor crudo
 * ya vive en `provider_metadata` de cada fila), ampliado a los 3 campos
 * afectados por ese hito, con medición explícita ANTES de escribir.
 *
 * Seguridad, por diseño:
 * - **Dry-run por defecto**: sin `--apply`, el comando SOLO reporta lo que
 *   cambiaría — cero escrituras. Hay que pasar `--apply` explícitamente
 *   para persistir algo.
 * - **Active nunca se toca sin pedirlo**: por defecto, los ejercicios
 *   `is_active=true` se EXCLUYEN de cualquier escritura (aunque el reporte
 *   los muestra por separado, para que se vea el panorama completo). Hace
 *   falta `--include-active` para que también se escriban.
 * - Nunca toca `workout_exercises`/`exercise_snapshot`/histórico — solo
 *   columnas de `exercises`.
 */
class BackfillExerciseNormalization extends Command
{
    protected $signature = 'exercises:backfill-normalization
        {provider=ymove}
        {--apply : Escribe los cambios. Sin esta bandera, el comando es un dry-run puro (cero escrituras).}
        {--include-active : También escribe sobre ejercicios Active. Por defecto, Active se reporta pero NUNCA se escribe.}';

    protected $description = 'Re-deriva equipment_needed/primary_muscle/secondary_muscles con el normalizer actual, sin llamar al proveedor. Dry-run por defecto.';

    public function handle(ProviderRegistry $registry): int
    {
        $providerKey = $this->argument('provider');
        $apply = (bool) $this->option('apply');
        $includeActive = (bool) $this->option('include-active');

        if (! $registry->has($providerKey)) {
            $this->error("Proveedor desconocido: '{$providerKey}'. Conocidos: ".implode(', ', $registry->knownKeys()));

            return self::FAILURE;
        }

        $normalizer = $registry->getNormalizer($providerKey);

        foreach (['mapEquipment', 'mapMuscleGroup', 'mapSecondaryMuscles'] as $method) {
            if (! method_exists($normalizer, $method)) {
                $this->error("El normalizer de '{$providerKey}' no expone {$method}() — este backfill no aplica a este proveedor.");

                return self::FAILURE;
            }
        }

        $this->info($apply
            ? "MODO ESCRITURA ({$providerKey})".($includeActive ? ' — incluye Active' : ' — Active excluido')
            : "DRY-RUN ({$providerKey}) — no se escribe nada. Pasa --apply para persistir."
        );
        $this->newLine();

        $exercises = Exercise::where('provider', $providerKey)->get();

        $stats = $this->emptyStats();
        $utilizable = [];

        foreach ($exercises as $exercise) {
            $meta = $exercise->provider_metadata ?? [];
            $rawEquipment = $meta['equipment'] ?? null;
            $rawMuscleGroup = $meta['muscleGroup'] ?? null;
            $rawSecondary = $meta['secondaryMuscles'] ?? [];

            $newEquipment = $normalizer->mapEquipment($rawEquipment, $meta);
            $newPrimaryMuscle = $normalizer->mapMuscleGroup($rawMuscleGroup, $exercise->provider_exercise_id, $exercise->name);
            $newSecondary = $normalizer->mapSecondaryMuscles($rawSecondary);
            $newSecondaryValues = array_map(fn ($m) => $m->value, $newSecondary);

            $oldEquipment = $exercise->equipment_needed ?? [];
            $oldPrimaryMuscle = $exercise->primary_muscle?->value;
            $oldSecondaryValues = array_map(fn ($m) => $m->value, $this->decodeSecondary($exercise));

            $equipmentChanged = $this->normalizeForCompare($newEquipment) !== $this->normalizeForCompare($oldEquipment);
            $muscleChanged = $newPrimaryMuscle?->value !== $oldPrimaryMuscle;
            $secondaryChanged = $this->normalizeForCompare($newSecondaryValues) !== $this->normalizeForCompare($oldSecondaryValues);

            if (! $equipmentChanged && ! $muscleChanged && ! $secondaryChanged) {
                continue;
            }

            $bucket = $exercise->is_active ? 'active' : 'pending';
            $stats[$bucket]['touched']++;

            if ($equipmentChanged) {
                $this->tallyEquipment($stats[$bucket], $oldEquipment, $newEquipment);
            }
            if ($muscleChanged) {
                $this->tallyMuscle($stats[$bucket], $oldPrimaryMuscle, $newPrimaryMuscle?->value);
            }
            if ($secondaryChanged) {
                $recovered = array_diff($newSecondaryValues, $oldSecondaryValues);
                $stats[$bucket]['secondary_recovered'] += count($recovered);
                if ($recovered !== []) {
                    $stats[$bucket]['secondary_recovered_exercises']++;
                }
            }

            $becomesUtilizable = ($muscleChanged && $oldPrimaryMuscle === null && $newPrimaryMuscle !== null)
                || ($equipmentChanged && in_array('unsupported', $oldEquipment, true) === false
                    && in_array('unsupported', $newEquipment, true) === false
                    && $oldEquipment !== $newEquipment);

            if (! $exercise->is_active && $becomesUtilizable) {
                $utilizable[] = [
                    'id' => $exercise->id,
                    'name' => $exercise->name_es ?? $exercise->name,
                    'equipment' => "{$this->fmt($oldEquipment)} -> {$this->fmt($newEquipment)}",
                    'primary_muscle' => ($oldPrimaryMuscle ?? 'NULL').' -> '.($newPrimaryMuscle?->value ?? 'NULL'),
                ];
            }

            $this->line(sprintf(
                '  #%d [%s] %s%s%s',
                $exercise->id,
                $exercise->is_active ? 'ACTIVE' : 'pending',
                $exercise->name_es ?? $exercise->name,
                $equipmentChanged ? "\n      equipment: {$this->fmt($oldEquipment)} -> {$this->fmt($newEquipment)}" : '',
                ($muscleChanged || $secondaryChanged)
                    ? "\n      muscle: ".($oldPrimaryMuscle ?? 'NULL').' -> '.($newPrimaryMuscle?->value ?? 'NULL')
                        .($secondaryChanged ? ' | secondary: '.$this->fmt($oldSecondaryValues).' -> '.$this->fmt($newSecondaryValues) : '')
                    : '',
            ));

            $shouldWrite = $apply && (! $exercise->is_active || $includeActive);

            if ($shouldWrite) {
                $exercise->update([
                    'equipment_needed' => $newEquipment,
                    'primary_muscle' => $newPrimaryMuscle,
                    'secondary_muscles' => $newSecondaryValues !== [] ? $newSecondaryValues : null,
                ]);
                $stats[$bucket]['written']++;
            }
        }

        $this->newLine();
        $this->printSummary($stats, $exercises->count(), $apply, $includeActive);

        if ($utilizable !== []) {
            $this->newLine();
            $this->info('Ejercicios pending_review potencialmente utilizables tras la normalización ('.count($utilizable).'):');
            foreach ($utilizable as $u) {
                $this->line("  #{$u['id']} {$u['name']} | equipment: {$u['equipment']} | primary_muscle: {$u['primary_muscle']}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function emptyStats(): array
    {
        $bucket = fn () => [
            'touched' => 0, 'written' => 0,
            'to_unsupported' => 0, 'from_unsupported' => 0,
            'new_equipment_values' => [],
            'muscle_recovered' => 0, 'muscle_still_null' => 0, 'muscle_changed_value' => 0,
            'secondary_recovered' => 0, 'secondary_recovered_exercises' => 0,
        ];

        return ['active' => $bucket(), 'pending' => $bucket()];
    }

    private function tallyEquipment(array &$bucket, array $old, array $new): void
    {
        $wasUnsupported = in_array(Equipment::Unsupported->value, $old, true);
        $isUnsupported = in_array(Equipment::Unsupported->value, $new, true);

        if (! $wasUnsupported && $isUnsupported) {
            $bucket['to_unsupported']++;
        }
        if ($wasUnsupported && ! $isUnsupported) {
            $bucket['from_unsupported']++;
        }

        foreach (array_diff($new, $old) as $addedValue) {
            $bucket['new_equipment_values'][$addedValue] = ($bucket['new_equipment_values'][$addedValue] ?? 0) + 1;
        }
    }

    private function tallyMuscle(array &$bucket, ?string $old, ?string $new): void
    {
        if ($old === null && $new !== null) {
            $bucket['muscle_recovered']++;
        } elseif ($old !== null && $new === null) {
            // No debería ocurrir con los mappings aditivos de este hito —
            // se cuenta igual, nunca se oculta, para que un dry-run lo
            // muestre si algún día pasara.
            $bucket['muscle_changed_value']++;
        } elseif ($old !== null && $new !== null && $old !== $new) {
            $bucket['muscle_changed_value']++;
        }
    }

    private function normalizeForCompare(array $values): array
    {
        sort($values);

        return $values;
    }

    private function fmt(array $values): string
    {
        return $values === [] ? '[]' : '['.implode(',', $values).']';
    }

    /**
     * `Exercise.secondary_muscles` se persiste como array de strings
     * (`->value` de MuscleFocus), no como objetos — se decodifica aquí
     * solo para comparar contra el resultado de mapSecondaryMuscles(),
     * que sí devuelve objetos MuscleFocus.
     *
     * @return array<int, \App\Training\Enums\MuscleFocus>
     */
    private function decodeSecondary(Exercise $exercise): array
    {
        return collect($exercise->secondary_muscles ?? [])
            ->map(fn ($v) => \App\Training\Enums\MuscleFocus::tryFrom($v))
            ->filter()
            ->values()
            ->all();
    }

    private function printSummary(array $stats, int $total, bool $apply, bool $includeActive): void
    {
        foreach (['pending' => 'PENDING_REVIEW', 'active' => 'ACTIVE'] as $key => $label) {
            $b = $stats[$key];
            $this->info("=== {$label} ===");
            $this->line("  ejercicios con al menos un cambio: {$b['touched']}");
            $this->line("  equipment -> Unsupported (antes no lo era): {$b['to_unsupported']}");
            $this->line("  equipment: sale de Unsupported: {$b['from_unsupported']}");
            if ($b['new_equipment_values'] !== []) {
                $this->line('  nuevos valores de equipment ganados (conteo de apariciones):');
                foreach ($b['new_equipment_values'] as $value => $count) {
                    $this->line("    {$value} => {$count}");
                }
            }
            $this->line("  primary_muscle recuperado (antes NULL, ahora con foco): {$b['muscle_recovered']}");
            $this->line("  primary_muscle cambia de un valor a otro (no debería, ver arriba si >0): {$b['muscle_changed_value']}");
            $this->line("  secondary_muscles: ejercicios que ganan al menos 1 nuevo: {$b['secondary_recovered_exercises']} (total de valores nuevos ganados: {$b['secondary_recovered']})");

            if ($key === 'active' && ! $includeActive) {
                $this->line('  (Active excluido de la escritura — pasa --include-active para también escribir aquí)');
            }
            $this->line("  {$b['written']} escritos realmente".($apply ? '' : ' (dry-run: siempre 0)'));
            $this->newLine();
        }

        $this->info("Total de ejercicios de '{$this->argument('provider')}' inspeccionados: {$total}");
    }
}
