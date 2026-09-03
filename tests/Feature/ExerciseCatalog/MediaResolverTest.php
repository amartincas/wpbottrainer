<?php

use App\ExerciseCatalog\MediaResolver;
use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('resolves a manual exercise\'s own stable video_url without calling any provider', function () {
    Http::fake(); // cualquier llamada de red aquí sería un fallo de diseño

    $exercise = Exercise::factory()->create(['video_url' => 'https://videos.example.test/demo.mp4']);

    $resolved = (new MediaResolver(new ProviderRegistry))->resolve($exercise);

    expect($resolved->url)->toBe('https://videos.example.test/demo.mp4');
    Http::assertNothingSent();
});

it('returns null for a manual exercise with no video_url at all', function () {
    $exercise = Exercise::factory()->create(['video_url' => null]);

    expect((new MediaResolver(new ProviderRegistry))->resolve($exercise))->toBeNull();
});

it('resolves a provider exercise\'s video fresh, through the provider, every time', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response([
        'data' => [[
            'id' => 'abc-123',
            'title' => 'Some exercise',
            'muscleGroup' => 'glutes',
            'equipment' => 'bodyweight',
            'videoUrl' => 'https://cdn.ymove.example/fresh.mp4?token=xyz',
        ]],
    ], 200)]);

    $exercise = Exercise::factory()->fromProvider('ymove', 'abc-123')->create();

    $resolved = (new MediaResolver(new ProviderRegistry))->resolve($exercise);

    expect($resolved->url)->toBe('https://cdn.ymove.example/fresh.mp4?token=xyz');
});

it('degrades gracefully to null when the provider fails to resolve media, without throwing', function () {
    Log::spy();
    Http::fake(['exercise-api.ymove.app/*' => Http::response('down', 503)]);

    $exercise = Exercise::factory()->fromProvider('ymove', 'abc-123')->create();

    expect((new MediaResolver(new ProviderRegistry))->resolve($exercise))->toBeNull();
});

it('degrades gracefully to null when the exercise references an unknown/unregistered provider', function () {
    $exercise = Exercise::factory()->fromProvider('a_provider_that_was_removed', 'abc-123')->create();

    expect((new MediaResolver(new ProviderRegistry))->resolve($exercise))->toBeNull();
});
