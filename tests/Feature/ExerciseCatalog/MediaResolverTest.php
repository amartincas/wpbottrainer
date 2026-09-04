<?php

use App\ExerciseCatalog\Enums\MediaVariant;
use App\ExerciseCatalog\MediaResolver;
use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use App\Models\ExerciseVideoAccess;
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
    // Hito 9.3 (corrección post-deploy) — resolveMedia() ahora usa el
    // endpoint directo GET /exercises/{id}, que responde con un solo
    // objeto (`data: {...}`), no un arreglo como el listado paginado.
    Http::fake(['exercise-api.ymove.app/*' => Http::response([
        'data' => [
            'id' => 'abc-123',
            'title' => 'Some exercise',
            'muscleGroup' => 'glutes',
            'equipment' => 'bodyweight',
            'videoUrl' => 'https://cdn.ymove.example/fresh.mp4?token=xyz',
        ],
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

// ── Hito 9.3 (post-deploy): registro persistente de accesos exitosos ────

it('records a successful provider video resolution in ExerciseVideoAccess', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response([
        'data' => ['id' => 'abc-123', 'title' => 'Some exercise', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight', 'videoUrl' => 'https://cdn.ymove.example/fresh.mp4?token=xyz'],
    ], 200)]);

    $exercise = Exercise::factory()->fromProvider('ymove', 'abc-123')->create();

    (new MediaResolver(new ProviderRegistry))->resolve($exercise, MediaVariant::WhiteBackground);

    expect(ExerciseVideoAccess::count())->toBe(1);
    $access = ExerciseVideoAccess::first();
    expect($access->exercise_id)->toBe($exercise->id);
    expect($access->provider)->toBe('ymove');
    expect($access->provider_exercise_id)->toBe('abc-123');
    expect($access->variant)->toBe('white_background');
    expect($access->resolved_at)->not->toBeNull();
});

it('never records an access for a manual (non-provider) exercise', function () {
    $exercise = Exercise::factory()->create(['video_url' => 'https://videos.example.test/demo.mp4']);

    (new MediaResolver(new ProviderRegistry))->resolve($exercise);

    expect(ExerciseVideoAccess::count())->toBe(0);
});

it('never records an access when resolution fails or returns null', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response('down', 503)]);
    $exercise = Exercise::factory()->fromProvider('ymove', 'abc-123')->create();

    (new MediaResolver(new ProviderRegistry))->resolve($exercise);

    expect(ExerciseVideoAccess::count())->toBe(0);
});

it('appends a new row on every successful resolution, building a real history', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response([
        'data' => ['id' => 'abc-123', 'title' => 'Some exercise', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight', 'videoUrl' => 'https://cdn.ymove.example/fresh.mp4?token=xyz'],
    ], 200)]);
    $exercise = Exercise::factory()->fromProvider('ymove', 'abc-123')->create();
    $resolver = new MediaResolver(new ProviderRegistry);

    $resolver->resolve($exercise);
    $resolver->resolve($exercise);

    expect(ExerciseVideoAccess::where('exercise_id', $exercise->id)->count())->toBe(2);
});

it('still returns the resolved media even if writing the access log itself fails unexpectedly', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::response([
        'data' => ['id' => 'abc-123', 'title' => 'Some exercise', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight', 'videoUrl' => 'https://cdn.ymove.example/fresh.mp4?token=xyz'],
    ], 200)]);

    // exercise_id apunta a un Exercise que no existe en la BD real -> el
    // insert en exercise_video_accesses violaría la FK y lanzaría. La
    // resolución del video en sí NUNCA debe verse afectada por esto.
    $exercise = Exercise::factory()->fromProvider('ymove', 'abc-123')->make();
    $exercise->id = 999999;
    $exercise->exists = true;

    $resolved = (new MediaResolver(new ProviderRegistry))->resolve($exercise);

    expect($resolved)->not->toBeNull();
    expect($resolved->url)->toBe('https://cdn.ymove.example/fresh.mp4?token=xyz');
});
