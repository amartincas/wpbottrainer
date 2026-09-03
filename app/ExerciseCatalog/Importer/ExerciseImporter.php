<?php

namespace App\ExerciseCatalog\Importer;

use App\ExerciseCatalog\DTOs\FullSyncResult;
use App\ExerciseCatalog\DTOs\NormalizedExerciseData;
use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\DTOs\SelectedImportResult;
use App\ExerciseCatalog\Exceptions\ProviderSyncException;
use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use App\Training\Enums\MuscleFocus;
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

        return $this->upsert($this->registry->getNormalizer($providerKey)->normalize($raw))['exercise'];
    }

    /**
     * @return Collection<int, Exercise>
     */
    public function importSearch(string $providerKey, ProviderSearchCriteria $criteria): Collection
    {
        $provider = $this->registry->get($providerKey);
        $normalizer = $this->registry->getNormalizer($providerKey);

        return $provider->search($criteria)
            ->map(fn (ProviderExerciseData $raw) => $this->upsert($normalizer->normalize($raw))['exercise'])
            ->values();
    }

    /**
     * Hito 9.3 — sincronización COMPLETA del catálogo del proveedor:
     * inventario de referencia, nunca un catálogo aprobado para
     * TrainingEngine (todo ejercicio nuevo sigue naciendo
     * is_active=false/contraindications=null, exactamente igual que
     * importSearch()/importSelected()).
     *
     * Recorre TODAS las páginas usando la paginación REAL que el
     * proveedor reporta (`searchPaged()`), nunca un número asumido. Si el
     * proveedor falla a mitad de camino, la corrida se detiene de
     * inmediato y NUNCA reconcilia bajas — una respuesta incompleta jamás
     * se trata como "el ejercicio desapareció" (ver docs/DECISIONS.md).
     * Solo tras un recorrido completo y limpio se desactivan (nunca se
     * borran) los `provider_exercise_id` activos que no aparecieron.
     *
     * `$hasVideo`: tri-estado — `null` (default) trae TODO el catálogo,
     * con y sin video, para un inventario de referencia realmente
     * completo; `true`/`false` filtran explícitamente si algún llamador
     * lo necesita.
     *
     * `$muscleFocus`: si se da, acota tanto la búsqueda (optimización,
     * menos páginas) COMO la reconciliación de bajas — un sync de
     * `quads` jamás puede tocar `is_active` de un ejercicio cuyo
     * `primary_muscle` en NUESTRA base sea otro músculo, sin importar qué
     * devuelva el proveedor. `null` (default) sincroniza y reconcilia
     * contra el catálogo completo del proveedor, como hasta ahora.
     */
    public function fullSync(string $providerKey, ?bool $hasVideo = null, ?MuscleFocus $muscleFocus = null): FullSyncResult
    {
        $provider = $this->registry->get($providerKey);
        $normalizer = $this->registry->getNormalizer($providerKey);

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $totalReceived = 0;
        $pagesProcessed = 0;
        $touchedIds = [];
        $totalPagesReported = null;
        $page = 1;
        // Guard defensivo, nunca alcanzable con el catálogo real de hoy
        // (1068 ejercicios paginados de a 20 = 54 páginas).
        $maxPages = 2000;

        while ($page <= $maxPages) {
            try {
                $pageResult = $provider->searchPaged(new ProviderSearchCriteria(muscleFocus: $muscleFocus, hasVideo: $hasVideo, page: $page));
            } catch (ProviderSyncException $e) {
                return new FullSyncResult(
                    totalReceived: $totalReceived,
                    created: $created,
                    updated: $updated,
                    unchanged: $unchanged,
                    possiblyRemoved: [],
                    pagesProcessed: $pagesProcessed,
                    completedFully: false,
                    errorMessage: $e->getMessage(),
                );
            }

            $pagesProcessed++;
            $totalPagesReported ??= $pageResult->totalPages;

            if ($pageResult->items->isEmpty()) {
                break; // fin natural del catálogo
            }

            foreach ($pageResult->items as $raw) {
                $totalReceived++;
                $touchedIds[] = $raw->providerExerciseId;
                $result = $this->upsert($normalizer->normalize($raw));
                match ($result['status']) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'unchanged' => $unchanged++,
                };
            }

            if ($totalPagesReported !== null && $page >= $totalPagesReported) {
                break; // el propio proveedor confirma que no hay más páginas
            }

            $page++;
        }

        // Reconciliación de "posiblemente desaparecidos" — solo se llega
        // aquí tras un recorrido completo y sin errores (ver el `return`
        // anticipado arriba ante cualquier ProviderSyncException). Acotada
        // por `primary_muscle` cuando el sync fue por músculo: un sync de
        // "quads" nunca puede desactivar un ejercicio de otro músculo.
        $reconciliationScope = fn () => Exercise::query()
            ->where('provider', $providerKey)
            ->when($muscleFocus !== null, fn ($q) => $q->where('primary_muscle', $muscleFocus->value))
            ->where('is_active', true);

        $possiblyRemovedIds = $reconciliationScope()
            ->whereNotIn('provider_exercise_id', $touchedIds)
            ->pluck('provider_exercise_id')
            ->all();

        if ($possiblyRemovedIds !== []) {
            $reconciliationScope()
                ->whereIn('provider_exercise_id', $possiblyRemovedIds)
                ->update(['is_active' => false]);
        }

        return new FullSyncResult(
            totalReceived: $totalReceived,
            created: $created,
            updated: $updated,
            unchanged: $unchanged,
            possiblyRemoved: $possiblyRemovedIds,
            pagesProcessed: $pagesProcessed,
            completedFully: true,
            errorMessage: null,
        );
    }

    /**
     * Hito 9.3 — importa EXACTAMENTE la lista de `provider_exercise_id`
     * dada (ej. un lote curado y aprobado manualmente), nunca de más.
     * `find()` no sirve para esto en un catálogo grande (pagina de a
     * pocos, sin filtro por id) — este método pagina `search()` (siempre
     * en modo browse) hasta localizar cada id pedido o agotar las páginas
     * aplicables.
     *
     * `$muscleHints` es solo una OPTIMIZACIÓN para reducir páginas
     * consultadas (se prueban en orden antes de cualquier otra cosa) —
     * nunca una condición de corrección. Se agote o no un hint, siempre
     * hay una pasada final SIN filtro de músculo sobre el catálogo
     * completo para cualquier id que aún falte; la autoridad final de
     * "encontrado" es siempre el `provider_exercise_id` exacto, nunca el
     * músculo bajo el que se buscó.
     *
     * @param  string[]  $providerExerciseIds
     * @param  MuscleFocus[]  $muscleHints
     */
    public function importSelected(string $providerKey, array $providerExerciseIds, array $muscleHints = []): SelectedImportResult
    {
        $provider = $this->registry->get($providerKey);
        $normalizer = $this->registry->getNormalizer($providerKey);

        $counts = array_count_values($providerExerciseIds);
        $duplicatesInRequest = array_keys(array_filter($counts, fn (int $c) => $c > 1));
        $remaining = collect(array_keys($counts));

        $found = collect();
        $pagesConsulted = 0;
        // Guard defensivo contra un bucle infinito si search() nunca
        // devolviera vacío — nunca alcanzable con el catálogo real de hoy
        // (1068 ejercicios / paginado), muy por debajo de este límite.
        $maxPagesPerScope = 500;

        // hasVideo: true explícito — los candidatos de un lote curado
        // siempre se buscaron originalmente entre ejercicios CON video.
        $scopes = array_map(
            fn (MuscleFocus $focus) => new ProviderSearchCriteria(muscleFocus: $focus, hasVideo: true),
            $muscleHints,
        );
        $scopes[] = new ProviderSearchCriteria(hasVideo: true); // pasada final sin filtro de músculo, siempre se ejecuta

        foreach ($scopes as $scope) {
            if ($remaining->isEmpty()) {
                break;
            }

            for ($page = 1; $page <= $maxPagesPerScope && $remaining->isNotEmpty(); $page++) {
                $results = $provider->search(new ProviderSearchCriteria(
                    muscleFocus: $scope->muscleFocus,
                    hasVideo: $scope->hasVideo,
                    page: $page,
                ));
                $pagesConsulted++;

                if ($results->isEmpty()) {
                    break; // esta página (y por tanto este scope) se agotó
                }

                foreach ($results as $raw) {
                    if (! $found->has($raw->providerExerciseId) && $remaining->contains($raw->providerExerciseId)) {
                        $found->put($raw->providerExerciseId, $raw);
                        $remaining = $remaining->reject(fn (string $id) => $id === $raw->providerExerciseId)->values();
                    }
                }
            }
        }

        $imported = $found
            ->map(fn (ProviderExerciseData $raw) => $this->upsert($normalizer->normalize($raw))['exercise'])
            ->values();

        return new SelectedImportResult(
            imported: $imported,
            notFound: $remaining->values()->all(),
            duplicatesInRequest: $duplicatesInRequest,
            pagesConsulted: $pagesConsulted,
        );
    }

    /**
     * @return array{exercise: Exercise, status: 'created'|'updated'|'unchanged'}
     */
    private function upsert(NormalizedExerciseData $data): array
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
            'provider_has_video' => $data->hasVideo,
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

            return ['exercise' => Exercise::create($attributes), 'status' => 'created'];
        }

        // Re-sync: common_mistakes/breathing_cue NUNCA se incluyen aquí —
        // preserva cualquier curación humana existente, exactamente igual
        // que is_active/contraindications.
        $existing->update($attributes);

        // "updated" debe reflejar si algún campo del CONTRATO normalizado
        // cambió, nunca ruido ajeno a eso:
        // - `synced_at` cambia SIEMPRE (es `now()` en cada corrida);
        // - `provider_metadata` es el payload crudo opaco del proveedor,
        //   nunca parte del contrato normalizado — y, hallazgo real
        //   verificado contra la base (MySQL 8.0): el motor reordena las
        //   claves de un objeto JSON al almacenarlo (nunca los elementos
        //   de un array JSON, solo las claves de un objeto), así que
        //   comparar este campo con wasChanged() da un falso "cambió" en
        //   CADA re-sync, incluso con contenido lógicamente idéntico.
        $meaningfulKeys = array_diff(array_keys($attributes), ['synced_at', 'provider_metadata']);
        $status = $existing->wasChanged($meaningfulKeys) ? 'updated' : 'unchanged';

        return ['exercise' => $existing->fresh(), 'status' => $status];
    }
}
