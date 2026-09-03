<?php

use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\Enums\MediaVariant;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseProvider;
use App\Training\Enums\MuscleFocus;
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

    (new YMoveExerciseProvider)->search(new ProviderSearchCriteria(muscleFocus: MuscleFocus::Glutes));

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'muscleGroup=glutes')
        && str_contains((string) $request->url(), 'hasVideo=true')
        && $request->hasHeader('X-API-Key'));
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
