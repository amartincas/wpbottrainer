<?php

use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\Enums\MediaResolutionReason;
use App\ExerciseCatalog\Enums\MediaVariant;
use App\ExerciseCatalog\Exceptions\ProviderSyncException;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseProvider;
use App\Training\Enums\MuscleFocus;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Hito 9.1: único archivo que conoce el shape HTTP real de YMove
 * (auditado en la prueba técnica) — nunca se llama a la API real en tests.
 */
function fakeYMoveExercise(array $overrides = []): array
{
    return array_merge([
        'id' => '5990deac-a91d-4531-8c2f-a708fc95fd1f',
        'title' => 'Barbell Hip Thrust',
        'muscleGroup' => 'glutes',
        'equipment' => 'barbell',
        'difficulty' => null,
        'hasVideo' => true,
        'videoUrl' => 'https://cdn.example/default.mp4?token=abc&expires=1788552888',
        'videos' => [
            ['videoUrl' => 'https://cdn.example/white.mp4?expires=1788552888', 'tag' => 'white-background'],
            ['videoUrl' => 'https://cdn.example/gym.mp4?expires=1788552888', 'tag' => 'gym-shot'],
        ],
    ], $overrides);
}

/**
 * Hito 9.3 (corrección post-deploy) — shape real de `GET /exercises/{id}`,
 * verificado en vivo contra la cuenta real en modo browse (gratis, cero
 * cuota): `{"data": {...un solo objeto, no un arreglo...}}`, más `_notice`/
 * `_warning` como hermanos de `data` según el caso.
 */
function fakeYMoveSingleItem(array $item, ?array $warning = null): PromiseInterface
{
    return Http::response(array_filter([
        'data' => $item,
        '_warning' => $warning,
    ], fn ($v) => $v !== null), 200);
}

it('sends the muscle focus filter using YMove\'s own query parameter', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => [fakeYMoveExercise()]], 200)]);

    (new YMoveExerciseProvider)->search(new ProviderSearchCriteria(muscleFocus: MuscleFocus::Glutes, hasVideo: true));

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'muscleGroup=glutes')
        && str_contains((string) $request->url(), 'hasVideo=true')
        && $request->hasHeader('X-API-Key'));
});

/**
 * Hito 9.3 — `hasVideo` es tri-estado: `null` (el default) no envía el
 * parámetro en absoluto, para que un inventario de referencia completo
 * pueda traer ejercicios con y sin video. Antes era `hasVideoOnly: bool`,
 * siempre `true` — este test documenta el cambio de comportamiento.
 */
it('omits the hasVideo filter entirely by default, bringing both with-video and without-video exercises', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => [fakeYMoveExercise()]], 200)]);

    (new YMoveExerciseProvider)->search(new ProviderSearchCriteria);

    Http::assertSent(fn ($request) => ! str_contains((string) $request->url(), 'hasVideo='));
});

it('explicitly filters by hasVideo=false when asked, for exercises without video', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => []], 200)]);

    (new YMoveExerciseProvider)->search(new ProviderSearchCriteria(hasVideo: false));

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'hasVideo=false'));
});

it('returns provider exercise data for each item in the search results', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => [fakeYMoveExercise(), fakeYMoveExercise(['id' => 'other-id'])]], 200)]);

    $results = (new YMoveExerciseProvider)->search(new ProviderSearchCriteria);

    expect($results)->toHaveCount(2);
    expect($results->first()->providerExerciseId)->toBe('5990deac-a91d-4531-8c2f-a708fc95fd1f');
});

it('returns an empty collection when the provider request fails, never throwing', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response('error', 500)]);

    expect((new YMoveExerciseProvider)->search(new ProviderSearchCriteria))->toHaveCount(0);
});

it('reports available when the provider responds successfully', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => []], 200)]);

    expect((new YMoveExerciseProvider)->isAvailable())->toBeTrue();
});

it('reports unavailable when the provider fails', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response('down', 503)]);

    expect((new YMoveExerciseProvider)->isAvailable())->toBeFalse();
});

it('returns no alternatives — YMove does not offer this concept, and the contract does not require it', function () {
    expect((new YMoveExerciseProvider)->alternatives('anything'))->toBe([]);
});

it('searchPaged exposes the real pagination metadata reported by the provider', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response([
        'data' => [fakeYMoveExercise()],
        'pagination' => ['page' => 3, 'pageSize' => 20, 'total' => 1068, 'totalPages' => 54],
    ], 200)]);

    $page = (new YMoveExerciseProvider)->searchPaged(new ProviderSearchCriteria(page: 3));

    expect($page->items)->toHaveCount(1);
    expect($page->page)->toBe(3);
    expect($page->totalPages)->toBe(54);
    expect($page->totalItems)->toBe(1068);
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=false')
        && str_contains((string) $request->url(), 'page=3'));
});

it('searchPaged throws instead of silently returning an empty page when the provider fails', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response('down', 503)]);

    (new YMoveExerciseProvider)->searchPaged(new ProviderSearchCriteria(page: 5));
})->throws(ProviderSyncException::class);

// ── Hito 9.3 (corrección post-deploy): GET /exercises/{id} ──────────────
//
// Hallazgo real en la documentación oficial de YMove: SÍ existe un
// endpoint directo por id (la suposición anterior — "hay que listar y
// buscar" — era incorrecta). find()/resolveMedia()/variants() ya NO
// recorren el catálogo paginado: cada resolución de un ejercicio
// individual es exactamente 1 solicitud HTTP.

it('find() uses the direct GET /exercises/{id} endpoint with includeVideos=false, never the paginated catalog', function () {
    Http::fake(['exercise-api.ymove.app/*' => fakeYMoveSingleItem(fakeYMoveExercise())]);

    $found = (new YMoveExerciseProvider)->find('5990deac-a91d-4531-8c2f-a708fc95fd1f');

    expect($found)->not->toBeNull();
    expect($found->providerExerciseId)->toBe('5990deac-a91d-4531-8c2f-a708fc95fd1f');
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/exercises/5990deac-a91d-4531-8c2f-a708fc95fd1f')
        && str_contains((string) $request->url(), 'includeVideos=false')
        && ! str_contains($request->url(), 'page='));
    // Exactamente 1 solicitud — nunca un recorrido de páginas.
    Http::assertSentCount(1);
});

it('finds a UUID that would never have been on the first page of the catalog, with a single direct request', function () {
    // El id no tiene ninguna relación con "página 1" — el endpoint
    // directo por id hace que ese concepto ya no aplique en absoluto.
    $farAwayId = 'zzz99999-far-from-page-one-9999-000000000000';
    Http::fake(['exercise-api.ymove.app/*' => fakeYMoveSingleItem(fakeYMoveExercise(['id' => $farAwayId]))]);

    $found = (new YMoveExerciseProvider)->find($farAwayId);

    expect($found)->not->toBeNull();
    expect($found->providerExerciseId)->toBe($farAwayId);
    Http::assertSentCount(1);
});

it('resolveMedia() uses the direct GET /exercises/{id} endpoint with includeVideos=true', function () {
    Http::fake(['exercise-api.ymove.app/*' => fakeYMoveSingleItem(fakeYMoveExercise())]);

    $resolved = (new YMoveExerciseProvider)->resolveMedia('5990deac-a91d-4531-8c2f-a708fc95fd1f');

    expect($resolved)->not->toBeNull();
    expect($resolved->url)->toBe('https://cdn.example/default.mp4?token=abc&expires=1788552888');
    expect($resolved->contentType)->toBe('video/mp4');
    expect($resolved->expiresAt->getTimestamp())->toBe(1788552888);
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/exercises/5990deac-a91d-4531-8c2f-a708fc95fd1f')
        && str_contains((string) $request->url(), 'includeVideos=true'));
    Http::assertSentCount(1);
});

it('resolves the white-background variant specifically when requested', function () {
    Http::fake(['exercise-api.ymove.app/*' => fakeYMoveSingleItem(fakeYMoveExercise())]);

    $resolved = (new YMoveExerciseProvider)->resolveMedia('5990deac-a91d-4531-8c2f-a708fc95fd1f', MediaVariant::WhiteBackground);

    expect($resolved->url)->toBe('https://cdn.example/white.mp4?expires=1788552888');
});

it('resolveMedia() resolves an exercise that would never have been on the first page, with a single direct request', function () {
    $farAwayId = 'off-catalog-order-id';
    Http::fake(['exercise-api.ymove.app/*' => fakeYMoveSingleItem(fakeYMoveExercise(['id' => $farAwayId]))]);

    $resolved = (new YMoveExerciseProvider)->resolveMedia($farAwayId);

    expect($resolved)->not->toBeNull();
    Http::assertSentCount(1);
});

it('returns null (provider_exercise_not_found) when the provider responds 404 for the id', function () {
    Log::spy();
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['message' => 'not found'], 404)]);

    expect((new YMoveExerciseProvider)->resolveMedia('does-not-exist'))->toBeNull();
    expect((new YMoveExerciseProvider)->find('does-not-exist'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'EXERCISE_MEDIA_RESOLUTION'
            && $context['reason'] === MediaResolutionReason::ExerciseNotFound->value
            && $context['http_status'] === 404)
        ->twice();
});

it('lists the distinct video tags as variants, using the direct endpoint', function () {
    Http::fake(['exercise-api.ymove.app/*' => fakeYMoveSingleItem(fakeYMoveExercise())]);

    expect((new YMoveExerciseProvider)->variants('5990deac-a91d-4531-8c2f-a708fc95fd1f'))
        ->toEqualCanonicalizing(['white-background', 'gym-shot']);
    Http::assertSentCount(1);
});

it('never requests video for find(), only for resolveMedia()/variants() — same guarantee, now on the direct endpoint', function () {
    Http::fake(['exercise-api.ymove.app/*' => fakeYMoveSingleItem(fakeYMoveExercise())]);

    $provider = new YMoveExerciseProvider;
    $provider->find('5990deac-a91d-4531-8c2f-a708fc95fd1f');

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=false'));
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=true'));

    $provider->resolveMedia('5990deac-a91d-4531-8c2f-a708fc95fd1f');
    $provider->variants('5990deac-a91d-4531-8c2f-a708fc95fd1f');

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=true'));
});

// ── Observabilidad: logging estructurado (mantenido y adaptado) ─────────

it('logs provider_has_no_video when the exercise is found but genuinely has no video', function () {
    Log::spy();
    Http::fake(['exercise-api.ymove.app/*' => fakeYMoveSingleItem(fakeYMoveExercise(['hasVideo' => false, 'videoUrl' => null, 'videos' => []]))]);

    expect((new YMoveExerciseProvider)->resolveMedia('5990deac-a91d-4531-8c2f-a708fc95fd1f'))->toBeNull();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'EXERCISE_MEDIA_RESOLUTION'
            && $context['provider'] === 'ymove'
            && $context['provider_exercise_id'] === '5990deac-a91d-4531-8c2f-a708fc95fd1f'
            && $context['operation'] === 'resolveMedia'
            && $context['phase'] === 'video'
            && $context['reason'] === MediaResolutionReason::HasNoVideo->value
            && ! isset($context['http_status']))
        ->once();
});

/**
 * Hito 9.3 (corrección post-deploy) — hallazgo real de la documentación
 * de YMove: al superar el cupo mensual, la API responde 200 (no 429) con
 * la metadata completa pero sin campos de video, marcando
 * `_warning.reason = "monthly_exercise_cap"`. Sin este chequeo explícito
 * sería indistinguible de "el ejercicio genuinamente no tiene video".
 */
it('logs provider_quota_exceeded when the provider responds 200 with _warning.reason=monthly_exercise_cap, never as provider_has_no_video', function () {
    Log::spy();
    $capped = fakeYMoveExercise();
    unset($capped['videoUrl'], $capped['videos']);
    Http::fake(['exercise-api.ymove.app/*' => fakeYMoveSingleItem($capped, warning: ['reason' => 'monthly_exercise_cap'])]);

    expect((new YMoveExerciseProvider)->resolveMedia('5990deac-a91d-4531-8c2f-a708fc95fd1f'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'EXERCISE_MEDIA_RESOLUTION'
            && $context['operation'] === 'resolveMedia'
            && $context['phase'] === 'video'
            && $context['reason'] === MediaResolutionReason::QuotaExceeded->value)
        ->once();
    Log::shouldNotHaveReceived('info');
});

it('also logs provider_quota_exceeded when the provider rejects the video request outright with HTTP 429', function () {
    Log::spy();
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['error' => 'monthly quota exceeded'], 429)]);

    expect((new YMoveExerciseProvider)->resolveMedia('5990deac-a91d-4531-8c2f-a708fc95fd1f'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'EXERCISE_MEDIA_RESOLUTION'
            && $context['reason'] === MediaResolutionReason::QuotaExceeded->value
            && $context['http_status'] === 429)
        ->once();
});

it('logs provider_http_error (never quota, never not-found) for a generic HTTP failure', function () {
    Log::spy();
    Http::fake(['exercise-api.ymove.app/*' => Http::response('down', 503)]);

    expect((new YMoveExerciseProvider)->find('5990deac-a91d-4531-8c2f-a708fc95fd1f'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'EXERCISE_MEDIA_RESOLUTION'
            && $context['operation'] === 'find'
            && $context['phase'] === 'browse'
            && $context['reason'] === MediaResolutionReason::HttpError->value
            && $context['http_status'] === 503)
        ->once();
});

it('never logs an API key, a token, or a signed video URL in a resolution event', function () {
    Log::spy();
    Http::fake(['exercise-api.ymove.app/*' => Http::response('unauthorized', 401)]);

    (new YMoveExerciseProvider)->resolveMedia('5990deac-a91d-4531-8c2f-a708fc95fd1f');

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) {
            $flat = json_encode($context);

            return ! str_contains($flat, 'cdn.example')
                && ! str_contains($flat, 'X-API-Key')
                && ! str_contains($flat, 'token=');
        })
        ->once();
});

/**
 * Hito 9.3 (post-deploy) — el vocabulario de motivos (MediaResolutionReason)
 * y el mecanismo de log son agnósticos de proveedor: ningún valor del
 * enum menciona YMove, y un segundo proveedor de mentira puede clasificar
 * sus propios fallos con el mismo vocabulario sin ningún cambio en
 * MediaResolver/TrainingEngine.
 */
it('the media resolution reason vocabulary never hardcodes a specific provider name', function () {
    foreach (MediaResolutionReason::cases() as $case) {
        expect(mb_stripos($case->value, 'ymove'))->toBeFalse();
        expect(mb_stripos($case->name, 'ymove'))->toBeFalse();
    }
});
