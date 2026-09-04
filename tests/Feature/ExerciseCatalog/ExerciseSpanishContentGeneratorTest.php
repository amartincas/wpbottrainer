<?php

use App\ExerciseCatalog\Curation\Exceptions\SpanishContentGenerationException;
use App\ExerciseCatalog\Curation\ExerciseSpanishContentGenerator;
use App\Models\Exercise;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

/**
 * Hito 9.3 (fix post-E2E) — 100% con mocks (cero llamadas reales a
 * OpenAI/YMove, cero cuota). Ver App\ExerciseCatalog\Curation\
 * ExerciseSpanishContentGenerator.
 */
function fakeSpanishContentResponse(array $payload): void
{
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload)]]],
        ], 200),
    ]);
}

it('generates validated Spanish content preserving step count and order', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name' => 'Barbell Hip Thrust',
        'instructions' => ['Set your shoulders on the bench.', 'Drive your hips up.'],
        'important_points' => ['Keep your chin tucked.'],
    ]);
    $tenant = Tenant::factory()->create();

    fakeSpanishContentResponse([
        'name' => 'Empuje de cadera con barra',
        'instructions' => ['Apoya los hombros en el banco.', 'Empuja las caderas hacia arriba.'],
        'important_points' => ['Mantén el mentón hacia adentro.'],
    ]);

    $result = (new ExerciseSpanishContentGenerator)->generate($exercise, $tenant);

    expect($result->name)->toBe('Empuje de cadera con barra');
    expect($result->instructions)->toBe(['Apoya los hombros en el banco.', 'Empuja las caderas hacia arriba.']);
    expect($result->importantPoints)->toBe(['Mantén el mentón hacia adentro.']);
});

it('sends the original exercise content and never mentions any specific provider in the prompt', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name' => 'Squat',
        'instructions' => ['Step 1'],
        'important_points' => [],
    ]);
    $tenant = Tenant::factory()->create();

    fakeSpanishContentResponse(['name' => 'Sentadilla', 'instructions' => ['Paso 1'], 'important_points' => []]);

    (new ExerciseSpanishContentGenerator)->generate($exercise, $tenant);

    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($body, 'Squat')
            && str_contains($body, 'Step 1')
            && ! str_contains(mb_strtolower($body), 'ymove');
    });
});

it('accepts an empty important_points array unchanged, never inventing content', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'instructions' => ['Paso único'],
        'important_points' => [],
    ]);
    $tenant = Tenant::factory()->create();

    fakeSpanishContentResponse(['name' => 'Traducido', 'instructions' => ['Paso único traducido'], 'important_points' => []]);

    $result = (new ExerciseSpanishContentGenerator)->generate($exercise, $tenant);

    expect($result->importantPoints)->toBe([]);
});

it('rejects the result when the AI returns invalid JSON', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create(['instructions' => ['Paso 1']]);
    $tenant = Tenant::factory()->create();

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'not json at all']]],
        ], 200),
    ]);

    (new ExerciseSpanishContentGenerator)->generate($exercise, $tenant);
})->throws(SpanishContentGenerationException::class);

it('rejects a translation that drops or invents instruction steps (fidelity guard, not just prompt trust)', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'instructions' => ['Paso 1', 'Paso 2', 'Paso 3'],
        'important_points' => [],
    ]);
    $tenant = Tenant::factory()->create();

    // La IA "fusionó" dos pasos en uno — violación de fidelidad estructural.
    fakeSpanishContentResponse(['name' => 'X', 'instructions' => ['Paso 1 y 2 juntos', 'Paso 3'], 'important_points' => []]);

    (new ExerciseSpanishContentGenerator)->generate($exercise, $tenant);
})->throws(SpanishContentGenerationException::class);

it('rejects a translation that invents important_points not present in the original', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'instructions' => ['Paso 1'],
        'important_points' => [],
    ]);
    $tenant = Tenant::factory()->create();

    fakeSpanishContentResponse(['name' => 'X', 'instructions' => ['Paso 1'], 'important_points' => ['Punto inventado']]);

    (new ExerciseSpanishContentGenerator)->generate($exercise, $tenant);
})->throws(SpanishContentGenerationException::class);

it('rejects a missing or empty name', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create(['instructions' => ['Paso 1'], 'important_points' => []]);
    $tenant = Tenant::factory()->create();

    fakeSpanishContentResponse(['name' => '', 'instructions' => ['Paso 1'], 'important_points' => []]);

    (new ExerciseSpanishContentGenerator)->generate($exercise, $tenant);
})->throws(SpanishContentGenerationException::class);

it('strips markdown code fences before parsing, same tolerance as OnboardingConversationService', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create(['instructions' => ['Paso 1'], 'important_points' => []]);
    $tenant = Tenant::factory()->create();

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => "```json\n".json_encode(['name' => 'X', 'instructions' => ['Paso 1'], 'important_points' => []])."\n```"]]],
        ], 200),
    ]);

    $result = (new ExerciseSpanishContentGenerator)->generate($exercise, $tenant);

    expect($result->name)->toBe('X');
});
