<?php

use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\Importer\ExerciseImporter;
use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function fakeYMoveSearchResponse(array $exercises): void
{
    Http::fake(['exercise-api.ymove.app/*' => Http::response(['data' => $exercises], 200)]);
}

it('imports a new exercise as inactive with unreviewed contraindications, never guessing safety', function () {
    fakeYMoveSearchResponse([[
        'id' => 'abc-123',
        'title' => 'Barbell Hip Thrust',
        'muscleGroup' => 'glutes',
        'equipment' => 'barbell',
        'difficulty' => null,
    ]]);

    $imported = (new ExerciseImporter(new ProviderRegistry))->importSearch('ymove', new ProviderSearchCriteria);

    expect($imported)->toHaveCount(1);
    $exercise = $imported->first();

    expect($exercise->provider)->toBe('ymove');
    expect($exercise->provider_exercise_id)->toBe('abc-123');
    expect($exercise->name)->toBe('Barbell Hip Thrust');
    expect($exercise->primary_muscle)->toBe(\App\Training\Enums\MuscleFocus::Glutes);
    expect($exercise->difficulty_level)->toBeNull();
    expect($exercise->is_active)->toBeFalse();
    expect($exercise->contraindications)->toBeNull();
    expect($exercise->video_url)->toBeNull();
    // Hito 9.2: common_mistakes/breathing_cue nunca vienen del proveedor —
    // ni siquiera al crear, quedan en null hasta una curación humana.
    expect($exercise->common_mistakes)->toBeNull();
    expect($exercise->breathing_cue)->toBeNull();
});

it('stores important_points from the provider, but never touches a curated common_mistakes/breathing_cue on re-sync', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::sequence()
        ->push(['data' => [[
            'id' => 'abc-123', 'title' => 'Barbell Hip Thrust', 'muscleGroup' => 'glutes', 'equipment' => 'barbell',
            'instructions' => ['Paso 1'], 'importantPoints' => ['Punto 1'],
        ]]], 200)
        ->push(['data' => [[
            'id' => 'abc-123', 'title' => 'Barbell Hip Thrust', 'muscleGroup' => 'glutes', 'equipment' => 'barbell',
            'instructions' => ['Paso 1 actualizado'], 'importantPoints' => ['Punto 1 actualizado'],
        ]]], 200),
    ]);

    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->importSearch('ymove', new ProviderSearchCriteria);

    $exercise = Exercise::where('provider_exercise_id', 'abc-123')->first();
    expect($exercise->important_points)->toBe(['Punto 1']);

    // Curación humana manual, fuera del importer — nunca escrita por YMove.
    $exercise->update(['common_mistakes' => ['Curado a mano'], 'breathing_cue' => 'Curado a mano']);

    $importer->importSearch('ymove', new ProviderSearchCriteria);
    $exercise = $exercise->fresh();

    expect($exercise->important_points)->toBe(['Punto 1 actualizado']); // sí se refresca
    expect($exercise->instructions)->toBe(['Paso 1 actualizado']); // sí se refresca
    expect($exercise->common_mistakes)->toBe(['Curado a mano']); // preservado
    expect($exercise->breathing_cue)->toBe('Curado a mano'); // preservado
});

it('re-syncing an already-imported exercise updates its metadata but never touches is_active or contraindications', function () {
    // Http::fake() llamado dos veces en el mismo test no reemplaza de forma
    // confiable la respuesta anterior — Http::sequence() es la forma
    // correcta de simular llamadas sucesivas con respuestas distintas.
    Http::fake(['exercise-api.ymove.app/*' => Http::sequence()
        ->push(['data' => [['id' => 'abc-123', 'title' => 'Old name', 'muscleGroup' => 'glutes', 'equipment' => 'barbell', 'instructions' => ['Paso 1']]]], 200)
        ->push(['data' => [['id' => 'abc-123', 'title' => 'Updated name', 'muscleGroup' => 'glutes', 'equipment' => 'barbell', 'instructions' => ['Paso 1']]]], 200),
    ]);

    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->importSearch('ymove', new ProviderSearchCriteria);

    $reviewer = \App\Models\User::factory()->create();
    $exercise = Exercise::where('provider_exercise_id', 'abc-123')->first();
    $exercise->update(['contraindications' => []]);
    $exercise->activate($reviewer);

    $importer->importSearch('ymove', new ProviderSearchCriteria);

    $exercise = Exercise::where('provider_exercise_id', 'abc-123')->first();

    expect($exercise->name)->toBe('Updated name'); // metadata sí se actualiza
    expect($exercise->is_active)->toBeTrue(); // revisión humana preservada
    expect($exercise->contraindications)->toBe([]); // preservada, no reseteada a null
});

it('deactivates exercises no longer returned by the provider, without deleting them', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::sequence()
        ->push(['data' => [
            ['id' => 'keep-me', 'title' => 'Stays', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight', 'instructions' => ['Paso 1']],
            ['id' => 'remove-me', 'title' => 'Goes away', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight', 'instructions' => ['Paso 1']],
        ]], 200)
        ->push(['data' => [
            ['id' => 'keep-me', 'title' => 'Stays', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight', 'instructions' => ['Paso 1']],
        ]], 200),
    ]);

    $importer = new ExerciseImporter(new ProviderRegistry);
    $imported = $importer->importSearch('ymove', new ProviderSearchCriteria);
    foreach ($imported as $exercise) {
        $exercise->update(['contraindications' => []]);
        $exercise->activate(\App\Models\User::factory()->create());
    }

    Artisan::call('exercises:sync', ['provider' => 'ymove']);

    expect(Exercise::where('provider_exercise_id', 'keep-me')->first()->is_active)->toBeTrue();
    expect(Exercise::where('provider_exercise_id', 'remove-me')->first()->is_active)->toBeFalse();
    expect(Exercise::where('provider_exercise_id', 'remove-me')->exists())->toBeTrue(); // nunca borrado
});

it('only imports for the muscle focus requested, when the sync command is scoped', function () {
    fakeYMoveSearchResponse([
        ['id' => 'glute-1', 'title' => 'Glute exercise', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight'],
    ]);

    Artisan::call('exercises:sync', ['provider' => 'ymove', '--muscle' => ['glutes']]);

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'muscleGroup=glutes'));
    expect(Exercise::where('provider_exercise_id', 'glute-1')->exists())->toBeTrue();
});
