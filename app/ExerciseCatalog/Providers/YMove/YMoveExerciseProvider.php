<?php

namespace App\ExerciseCatalog\Providers\YMove;

use App\ExerciseCatalog\Contracts\ExerciseProviderInterface;
use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\DTOs\ProviderSearchPage;
use App\ExerciseCatalog\DTOs\ResolvedMedia;
use App\ExerciseCatalog\Enums\MediaResolutionReason;
use App\ExerciseCatalog\Enums\MediaVariant;
use App\ExerciseCatalog\Exceptions\ProviderSyncException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        // catálogo. Nunca debe pedir video: ver resolveMedia()/variants()
        // para eso.
        return $this->fetchById($providerExerciseId, includeVideos: false, operation: 'find');
    }

    public function resolveMedia(string $providerExerciseId, MediaVariant $variant = MediaVariant::Default): ?ResolvedMedia
    {
        $data = $this->fetchById($providerExerciseId, includeVideos: true, operation: 'resolveMedia');

        if ($data === null) {
            return null;
        }

        $videoUrl = $this->pickVideoUrl($data->raw, $variant);

        if ($videoUrl === null) {
            // El ejercicio SÍ se localizó y el proveedor SÍ respondió con
            // éxito: esto no es un fallo de fetch (fetchById() ya lo
            // habría logueado distinto, incluida la cuota excedida — ver
            // ahí), es un dato real — este ejercicio no tiene video.
            $this->logResolutionEvent($providerExerciseId, 'resolveMedia', 'video', MediaResolutionReason::HasNoVideo);

            return null;
        }

        return new ResolvedMedia($videoUrl, 'video/mp4', $this->extractExpiry($videoUrl));
    }

    public function searchPaged(ProviderSearchCriteria $criteria): ProviderSearchPage
    {
        $page = $criteria->page ?? 1;
        $query = $this->buildBrowseQuery($criteria);
        $query['page'] = $page;

        // Ruta exclusiva de SINCRONIZACIÓN del catálogo completo — respeta
        // el filtro completo del criteria (muscleFocus/hasVideo). Nunca
        // usada para resolver UN ejercicio individual: ver fetchById().
        $response = $this->client()->get('/exercises', $query);

        if ($response->failed()) {
            // A diferencia de search(), NUNCA se silencia como colección
            // vacía — eso confundiría un fallo real de la API con "fin
            // del catálogo" en una sincronización completa.
            throw new ProviderSyncException(
                "YMove respondió con error al pedir la página {$page}: HTTP {$response->status()}."
            );
        }

        return $this->parsePage($response, $page);
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
        $data = $this->fetchById($providerExerciseId, includeVideos: true, operation: 'variants');

        if ($data === null) {
            return [];
        }

        $tags = collect($data->raw['videos'] ?? [])
            ->pluck('tag')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($tags === []) {
            $this->logResolutionEvent($providerExerciseId, 'variants', 'video', MediaResolutionReason::HasNoVideo);
        }

        return $tags;
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
     * Hito 9.3 (corrección post-deploy) — hallazgo real en la
     * documentación oficial de YMove: SÍ existe un endpoint directo por
     * id, `GET /exercises/{id}` (acepta UUID o slug) — la suposición
     * anterior ("no hay endpoint por id, hay que listar y buscar") era
     * incorrecta. Esta es ahora la ÚNICA vía para resolver UN ejercicio
     * individual — nunca recorre el catálogo paginado. `searchPaged()`
     * (arriba) se mantiene intacta para `fullSync()`, que sí necesita el
     * catálogo completo; ambas rutas son independientes a propósito:
     *
     *   CATÁLOGO (fullSync)      → searchPaged() → paginación
     *   EJERCICIO INDIVIDUAL     → fetchById()   → GET /exercises/{id}
     *
     * `includeVideos=false` en este mismo endpoint (equivalente a
     * `excludeVideos=1` según la documentación) evita el costo de cuota
     * — usado por `find()`. `includeVideos=true` (el único caso que
     * cuesta cuota) lo usan `resolveMedia()`/`variants()`.
     *
     * Un fallo real de HTTP se traduce a null aquí — find()/resolveMedia()/
     * variants() siguen prometiendo ese contrato, ningún llamador
     * (MediaResolver incluido) espera una excepción. Cada causa de
     * `null` se distingue en los logs (ver logResolutionEvent()),
     * incluida la respuesta 200-pero-sin-video que YMove entrega cuando
     * la cuenta superó su cupo mensual (`_warning.reason =
     * "monthly_exercise_cap"`, ver más abajo) — sin este chequeo sería
     * indistinguible de "el ejercicio genuinamente no tiene video".
     */
    private function fetchById(string $providerExerciseId, bool $includeVideos, string $operation): ?ProviderExerciseData
    {
        $phase = $includeVideos ? 'video' : 'browse';

        try {
            $response = $this->client()->get('/exercises/'.rawurlencode($providerExerciseId), [
                'includeVideos' => $includeVideos ? 'true' : 'false',
            ]);
        } catch (\Throwable $e) {
            $this->logResolutionEvent($providerExerciseId, $operation, $phase, MediaResolutionReason::UnexpectedError, error: $e->getMessage());

            return null;
        }

        if ($response->failed()) {
            $this->logHttpFailure($providerExerciseId, $operation, $phase, $response->status());

            return null;
        }

        $raw = $response->json('data');

        if (! is_array($raw) || ! isset($raw['id'])) {
            $this->logResolutionEvent($providerExerciseId, $operation, $phase, MediaResolutionReason::ExerciseNotFound);

            return null;
        }

        // Hito 9.3 (corrección post-deploy) — hallazgo real de la
        // documentación de YMove: al superar el cupo mensual de video,
        // la API responde 200 con la metadata completa pero SIN
        // videoUrl/videoHlsUrl/videoDurationSecs, marcando
        // `_warning.reason = "monthly_exercise_cap"`. Sin este chequeo
        // explícito, pickVideoUrl() simplemente no encontraría video y
        // esto se clasificaría (incorrectamente) como
        // provider_has_no_video — una causa completamente distinta.
        if ($includeVideos && $response->json('_warning.reason') === 'monthly_exercise_cap') {
            $this->logResolutionEvent($providerExerciseId, $operation, 'video', MediaResolutionReason::QuotaExceeded);

            return null;
        }

        return new ProviderExerciseData($raw['id'], $raw);
    }

    private function parsePage(Response $response, int $page): ProviderSearchPage
    {
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

    // ── Hito 9.3 (corrección post-deploy, observabilidad) ────────────────

    /**
     * Clasifica un fallo HTTP de fetchById() — 404 (id inexistente) y 429
     * (cuota) tienen cada uno su propio motivo; cualquier otro código es
     * un error genérico. Nunca se confunden entre sí en los logs.
     */
    private function logHttpFailure(string $providerExerciseId, string $operation, string $phase, int $httpStatus): void
    {
        $reason = match ($httpStatus) {
            404 => MediaResolutionReason::ExerciseNotFound,
            429 => MediaResolutionReason::QuotaExceeded,
            default => MediaResolutionReason::HttpError,
        };

        $this->logResolutionEvent($providerExerciseId, $operation, $phase, $reason, httpStatus: $httpStatus);
    }

    /**
     * Único punto de logging estructurado de este Adapter para fallos/
     * causas de resolución de media. Deliberadamente NUNCA incluye la
     * URL de video (firmada, con token) ni la API key — solo
     * identificadores y el motivo clasificado.
     */
    private function logResolutionEvent(
        string $providerExerciseId,
        string $operation,
        string $phase,
        MediaResolutionReason $reason,
        ?int $httpStatus = null,
        ?string $error = null,
    ): void {
        $level = $reason === MediaResolutionReason::HasNoVideo ? 'info' : 'warning';

        Log::{$level}('EXERCISE_MEDIA_RESOLUTION', array_filter([
            'provider' => $this->key(),
            'provider_exercise_id' => $providerExerciseId,
            'operation' => $operation,
            'phase' => $phase,
            'reason' => $reason->value,
            'http_status' => $httpStatus,
            'error' => $error,
        ], fn ($value) => $value !== null));
    }
}
