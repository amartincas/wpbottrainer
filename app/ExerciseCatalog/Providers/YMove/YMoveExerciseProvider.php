<?php

namespace App\ExerciseCatalog\Providers\YMove;

use App\ExerciseCatalog\Contracts\ExerciseProviderInterface;
use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\DTOs\ResolvedMedia;
use App\ExerciseCatalog\Enums\MediaVariant;
use App\Training\Enums\MuscleFocus;
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
        $query = [
            'hasVideo' => $criteria->hasVideoOnly ? 'true' : 'false',
            'includeVideos' => 'true',
        ];

        if ($criteria->muscleFocus !== null) {
            $query['muscleGroup'] = $criteria->muscleFocus->value;
        }

        if ($criteria->page !== null) {
            $query['page'] = $criteria->page;
        }

        $response = $this->client()->get('/exercises', $query);

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
        // La API pública auditada expone el detalle vía el listado
        // filtrado (no hay un endpoint /exercises/{id} confirmado en la
        // prueba técnica real) — se busca sin filtro de músculo y se
        // localiza por id. Aceptable para el volumen de MVP (sección 9 del
        // diseño); revisar si el catálogo real crece mucho más.
        $response = $this->client()->get('/exercises', [
            'includeVideos' => 'true',
        ]);

        if ($response->failed()) {
            return null;
        }

        $raw = collect($response->json('data', []))->firstWhere('id', $providerExerciseId);

        return $raw !== null ? new ProviderExerciseData($providerExerciseId, $raw) : null;
    }

    public function resolveMedia(string $providerExerciseId, MediaVariant $variant = MediaVariant::Default): ?ResolvedMedia
    {
        $data = $this->find($providerExerciseId);

        if ($data === null) {
            return null;
        }

        $videoUrl = $this->pickVideoUrl($data->raw, $variant);

        if ($videoUrl === null) {
            return null;
        }

        return new ResolvedMedia($videoUrl, 'video/mp4', $this->extractExpiry($videoUrl));
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
        $data = $this->find($providerExerciseId);

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

    private function client(): \Illuminate\Http\Client\PendingRequest
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
