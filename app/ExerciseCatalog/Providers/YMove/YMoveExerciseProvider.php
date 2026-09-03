<?php

namespace App\ExerciseCatalog\Providers\YMove;

use App\ExerciseCatalog\Contracts\ExerciseProviderInterface;
use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\DTOs\ProviderSearchPage;
use App\ExerciseCatalog\DTOs\ResolvedMedia;
use App\ExerciseCatalog\Enums\MediaVariant;
use App\ExerciseCatalog\Exceptions\ProviderSyncException;
use App\Training\Enums\MuscleFocus;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Hito 9.1 — único lugar del sistema que sabe que existe
 * `exercise-api.ymove.app`, el header `X-API-Key`, o el shape JSON exacto
 * de YMove (`muscleGroup`, `videoUrl`, `videos[]`, etc.). Todo lo demás del
 * dominio (TrainingEngine, TrainingHandler, MediaResolver-caller) solo
 * conoce ExerciseProviderInterface.
 *
 * `MuscleFocus->value` coincide 1:1 con el vocabulario `muscleGroup` de
 * YMove (glutes/quads/hamstrings/.../full_body) — confirmado en la
 * auditoría real de la prueba técnica (Hito 8.4/9), por eso no hace falta
 * una tabla de traducción para el parámetro de búsqueda.
 */
class YMoveExerciseProvider implements ExerciseProviderInterface
{
    public function key(): string
    {
        return 'ymove';
    }

    public function search(ProviderSearchCriteria $criteria): Collection
    {
        $response = $this->client()->get('/exercises', $this->buildBrowseQuery($criteria));

        if ($response->failed()) {
            return collect();
        }

        return collect($response->json('data', []))
            ->filter(fn (array $raw) => isset($raw['id']))
            ->map(fn (array $raw) => new ProviderExerciseData($raw['id'], $raw))
            ->values();
    }

    public function find(string $providerExerciseId): ?ProviderExerciseData
    {
        // Metadata únicamente (modo browse) — usado por la importación de
        // catálogo. Nunca debe pedir video: ver findWithVideo() para eso.
        return $this->fetchAndLocate($providerExerciseId, includeVideos: false);
    }

    public function resolveMedia(string $providerExerciseId, MediaVariant $variant = MediaVariant::Default): ?ResolvedMedia
    {
        $data = $this->findWithVideo($providerExerciseId);

        if ($data === null) {
            return null;
        }

        $videoUrl = $this->pickVideoUrl($data->raw, $variant);

        if ($videoUrl === null) {
            return null;
        }

        return new ResolvedMedia($videoUrl, 'video/mp4', $this->extractExpiry($videoUrl));
    }

    public function searchPaged(ProviderSearchCriteria $criteria): ProviderSearchPage
    {
        $page = $criteria->page ?? 1;
        $query = $this->buildBrowseQuery($criteria);
        $query['page'] = $page;

        $response = $this->client()->get('/exercises', $query);

        if ($response->failed()) {
            // A diferencia de search(), NUNCA se silencia como colección
            // vacía — eso confundiría un fallo real de la API con "fin
            // del catálogo" en una sincronización completa.
            throw new ProviderSyncException(
                "YMove respondió con error al pedir la página {$page}: HTTP {$response->status()}."
            );
        }

        $items = collect($response->json('data', []))
            ->filter(fn (array $raw) => isset($raw['id']))
            ->map(fn (array $raw) => new ProviderExerciseData($raw['id'], $raw))
            ->values();

        $pagination = $response->json('pagination');

        return new ProviderSearchPage(
            items: $items,
            page: (int) ($pagination['page'] ?? $page),
            totalPages: isset($pagination['totalPages']) ? (int) $pagination['totalPages'] : null,
            totalItems: isset($pagination['total']) ? (int) $pagination['total'] : null,
        );
    }

    public function isAvailable(): bool
    {
        try {
            return $this->client()->timeout(5)->get('/exercises', ['page' => 1])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function variants(string $providerExerciseId): array
    {
        // Enumerar variantes de video (fondo blanco/gimnasio) requiere el
        // arreglo `videos[]`, que el modo browse no trae — igual que
        // resolveMedia(), esta es una ruta que SÍ puede consumir cuota.
        $data = $this->findWithVideo($providerExerciseId);

        if ($data === null) {
            return [];
        }

        return collect($data->raw['videos'] ?? [])
            ->pluck('tag')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function alternatives(string $providerExerciseId): array
    {
        // YMove no ofrece este concepto en la API auditada — el contrato
        // lo permite, no lo obliga. [] es una respuesta válida y honesta.
        return [];
    }

    /**
     * Query compartida por search()/searchPaged() — siempre modo browse
     * (nunca cuota de video: ver docs/DECISIONS.md sobre el default de
     * `includeVideos` según fecha de creación de la key, nunca confiado).
     * `hasVideo` es tri-estado: `null` no envía el parámetro en absoluto
     * (YMove entonces no filtra, trae todo — con y sin video).
     */
    private function buildBrowseQuery(ProviderSearchCriteria $criteria): array
    {
        $query = ['includeVideos' => 'false'];

        if ($criteria->hasVideo !== null) {
            $query['hasVideo'] = $criteria->hasVideo ? 'true' : 'false';
        }

        if ($criteria->muscleFocus !== null) {
            $query['muscleGroup'] = $criteria->muscleFocus->value;
        }

        if ($criteria->page !== null) {
            $query['page'] = $criteria->page;
        }

        return $query;
    }

    /**
     * Igual que find(), pero con video incluido — SOLO para las dos rutas
     * del contrato que legítimamente lo necesitan (resolveMedia/variants).
     * Nunca invocada desde la importación de catálogo.
     */
    private function findWithVideo(string $providerExerciseId): ?ProviderExerciseData
    {
        return $this->fetchAndLocate($providerExerciseId, includeVideos: true);
    }

    private function fetchAndLocate(string $providerExerciseId, bool $includeVideos): ?ProviderExerciseData
    {
        // La API pública auditada expone el detalle vía el listado
        // filtrado (no hay un endpoint /exercises/{id} confirmado en la
        // prueba técnica real) — se busca sin filtro de músculo y se
        // localiza por id. Aceptable para el volumen de MVP (sección 9 del
        // diseño); revisar si el catálogo real crece mucho más.
        $response = $this->client()->get('/exercises', [
            'includeVideos' => $includeVideos ? 'true' : 'false',
        ]);

        if ($response->failed()) {
            return null;
        }

        $raw = collect($response->json('data', []))->firstWhere('id', $providerExerciseId);

        return $raw !== null ? new ProviderExerciseData($providerExerciseId, $raw) : null;
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'X-API-Key' => config('services.ymove.api_key'),
        ])->baseUrl(config('services.ymove.base_url'));
    }

    private function pickVideoUrl(array $raw, MediaVariant $variant): ?string
    {
        $tag = match ($variant) {
            MediaVariant::WhiteBackground => 'white-background',
            MediaVariant::GymShot => 'gym-shot',
            MediaVariant::Default => null,
        };

        if ($tag !== null) {
            $match = collect($raw['videos'] ?? [])->firstWhere('tag', $tag);

            if ($match !== null && isset($match['videoUrl'])) {
                return $match['videoUrl'];
            }
        }

        return $raw['videoUrl'] ?? null;
    }

    /**
     * El token de YMove incluye `expires` (epoch) en la query string — se
     * extrae solo para diagnóstico/logging, nunca para decidir si
     * persistir la URL (nunca se persiste, sin importar cuánto le quede).
     */
    private function extractExpiry(string $url): ?\DateTimeImmutable
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if ($query === null) {
            return null;
        }

        parse_str($query, $params);

        if (! isset($params['expires']) || ! ctype_digit((string) $params['expires'])) {
            return null;
        }

        return (new \DateTimeImmutable)->setTimestamp((int) $params['expires']);
    }
}
