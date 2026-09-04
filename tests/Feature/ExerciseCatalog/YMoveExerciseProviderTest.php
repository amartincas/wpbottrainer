<?php

use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\Enums\MediaVariant;
use App\ExerciseCatalog\Exceptions\ProviderSyncException;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseProvider;
use App\Training\Enums\MuscleFocus;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

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

/**
 * Hito 9.3 — hallazgo real: search()/find() tenían `includeVideos=true`
 * hardcodeado desde Hito 9.1, contradiciendo la política de "metadata en
 * modo browse, video solo bajo demanda" confirmada tras auditar la
 * documentación oficial de YMove. Este test documenta la garantía para
 * que no vuelva a regresar en un cambio futuro.
 */
it('never requests video when importing catalog metadata (search/find), only when resolving media', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => [fakeYMoveExercise()]], 200)]);

    $provider = new YMoveExerciseProvider;

    $provider->search(new ProviderSearchCriteria);
    $provider->find('5990deac-a91d-4531-8c2f-a708fc95fd1f');

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=false'));
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=true'));

    $provider->resolveMedia('5990deac-a91d-4531-8c2f-a708fc95fd1f');
    $provider->variants('5990deac-a91d-4531-8c2f-a708fc95fd1f');

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=true'));
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

it('resolves the default video URL when no variant is requested', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => [fakeYMoveExercise()]], 200)]);

    $resolved = (new YMoveExerciseProvider)->resolveMedia('5990deac-a91d-4531-8c2f-a708fc95fd1f');

    expect($resolved->url)->toBe('https://cdn.example/default.mp4?token=abc&expires=1788552888');
    expect($resolved->contentType)->toBe('video/mp4');
    expect($resolved->expiresAt->getTimestamp())->toBe(1788552888);
});

it('resolves the white-background variant specifically when requested', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => [fakeYMoveExercise()]], 200)]);

    $resolved = (new YMoveExerciseProvider)->resolveMedia('5990deac-a91d-4531-8c2f-a708fc95fd1f', MediaVariant::WhiteBackground);

    expect($resolved->url)->toBe('https://cdn.example/white.mp4?expires=1788552888');
});

it('returns null when the exercise no longer exists in the provider', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => []], 200)]);

    expect((new YMoveExerciseProvider)->resolveMedia('does-not-exist'))->toBeNull();
    expect((new YMoveExerciseProvider)->find('does-not-exist'))->toBeNull();
});

it('reports available when the provider responds successfully', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => []], 200)]);

    expect((new YMoveExerciseProvider)->isAvailable())->toBeTrue();
});

it('reports unavailable when the provider fails', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response('down', 503)]);

    expect((new YMoveExerciseProvider)->isAvailable())->toBeFalse();
});

it('lists the distinct video tags as variants', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => [fakeYMoveExercise()]], 200)]);

    expect((new YMoveExerciseProvider)->variants('5990deac-a91d-4531-8c2f-a708fc95fd1f'))
        ->toEqualCanonicalizing(['white-background', 'gym-shot']);
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

/**
 * Hito 9.3 (fix post-E2E) — hallazgo real de un test E2E: un ejercicio
 * fuera de la página 1 (~20 de 1068 ítems reales) siempre resolvía a
 * null, sin ningún error visible — el video simplemente no llegaba a
 * WhatsApp. Este bloque prueba la corrección de fetchAndLocate(), 100%
 * con mocks (cero llamadas reales a YMove, cero cuota).
 */
function fakeYMovePage(array $item, int $page, int $totalPages): PromiseInterface
{
    return Http::response([
        'data' => [$item],
        'pagination' => ['page' => $page, 'pageSize' => 20, 'total' => $totalPages * 20, 'totalPages' => $totalPages],
    ], 200);
}

it('resolves media for an exercise outside page 1 by paginating browse mode first, then fetching only that exact page with video', function () {
    $targetId = 'off-page-one-id';
    $page2WithVideo = fakeYMoveExercise(['id' => $targetId, 'videoUrl' => 'https://cdn.example/off-page.mp4?expires=1788552888']);
    // En modo browse, YMove nunca trae la URL real de video — se simula
    // sin videoUrl/videos para que localizar la página no pueda, por
    // accidente, resolver el video en la fase equivocada.
    $page2Browse = fakeYMoveExercise(['id' => $targetId]);
    unset($page2Browse['videoUrl'], $page2Browse['videos']);

    Http::fake(function ($request) use ($page2WithVideo, $page2Browse) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $includeVideos = ($query['includeVideos'] ?? 'false') === 'true';

        if ($page === 1) {
            return fakeYMovePage(fakeYMoveExercise(), page: 1, totalPages: 2);
        }

        return fakeYMovePage($includeVideos ? $page2WithVideo : $page2Browse, page: 2, totalPages: 2);
    });

    $resolved = (new YMoveExerciseProvider)->resolveMedia($targetId);

    expect($resolved)->not->toBeNull();
    expect($resolved->url)->toBe($page2WithVideo['videoUrl']);

    // Fase 1 (localizar): ambas páginas, siempre en modo browse.
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'page=1')
        && str_contains((string) $request->url(), 'includeVideos=false'));
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'page=2')
        && str_contains((string) $request->url(), 'includeVideos=false'));
    // Fase 2 (video): SOLO la página 2 — nunca la 1, nunca el catálogo completo.
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'page=2')
        && str_contains((string) $request->url(), 'includeVideos=true'));
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'page=1')
        && str_contains((string) $request->url(), 'includeVideos=true'));
});

it('still resolves media for an exercise on page 1, unchanged (no unnecessary extra pagination)', function () {
    Http::fake(function ($request) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

        return fakeYMovePage(fakeYMoveExercise(), page: (int) ($query['page'] ?? 1), totalPages: 3);
    });

    $resolved = (new YMoveExerciseProvider)->resolveMedia('5990deac-a91d-4531-8c2f-a708fc95fd1f');

    expect($resolved)->not->toBeNull();
    expect($resolved->url)->toBe('https://cdn.example/default.mp4?token=abc&expires=1788552888');
    // Encontrado ya en la página 1 — nunca debió pedir la página 2 ni 3.
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'page=2'));
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'page=3'));
});

it('find() locates metadata beyond page 1 by paginating browse mode, without ever requesting video', function () {
    $targetId = 'off-page-one-id';

    Http::fake(function ($request) use ($targetId) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);

        return $page === 1
            ? fakeYMovePage(fakeYMoveExercise(), page: 1, totalPages: 2)
            : fakeYMovePage(fakeYMoveExercise(['id' => $targetId]), page: 2, totalPages: 2);
    });

    $found = (new YMoveExerciseProvider)->find($targetId);

    expect($found)->not->toBeNull();
    expect($found->providerExerciseId)->toBe($targetId);
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=true'));
});

it('returns null (never an infinite loop) when the id does not exist on any real page of the catalog', function () {
    Http::fake(function ($request) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);

        return fakeYMovePage(fakeYMoveExercise(['id' => "page-{$page}-item"]), page: $page, totalPages: 3);
    });

    expect((new YMoveExerciseProvider)->find('never-exists'))->toBeNull();

    // Exactamente las 3 páginas reales del catálogo simulado — nunca más
    // (la condición de corte usa el totalPages real reportado).
    Http::assertSentCount(3);
});

it('stops after a single page (never loops) when the provider does not report pagination at all', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => []], 200)]);

    expect((new YMoveExerciseProvider)->find('anything'))->toBeNull();

    Http::assertSentCount(1);
});
