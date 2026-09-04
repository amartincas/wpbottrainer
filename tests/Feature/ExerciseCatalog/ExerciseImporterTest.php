<?php

use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\Importer\ExerciseImporter;
use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use App\Models\User;
use App\Training\Enums\MuscleFocus;
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
    expect($exercise->primary_muscle)->toBe(MuscleFocus::Glutes);
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

    $reviewer = User::factory()->create();
    $exercise = Exercise::where('provider_exercise_id', 'abc-123')->first();
    $exercise->update(['contraindications' => []]);
    $exercise->activate($reviewer);

    $importer->importSearch('ymove', new ProviderSearchCriteria);

    $exercise = Exercise::where('provider_exercise_id', 'abc-123')->first();

    expect($exercise->name)->toBe('Updated name'); // metadata sí se actualiza
    expect($exercise->is_active)->toBeTrue(); // revisión humana preservada
    expect($exercise->contraindications)->toBe([]); // preservada, no reseteada a null
});

/**
 * Hito 9.3 (fix post-E2E) — comprobación explícita pedida por el usuario
 * antes de commitear: name_es/instructions_es/important_points_es
 * sobreviven tanto a un re-sync (fullSync()/upsert() no los incluye en
 * $attributes, ver ExerciseImporter::upsert()) como a activate() (solo
 * escribe is_active/contraindications_reviewed_*, nunca contenido) — y
 * provider_has_video sigue siendo 100% independiente de is_active.
 */
it('preserves already-generated Spanish content through both a re-sync and activation, and keeps provider_has_video independent of is_active', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::sequence()
        ->push(['data' => [[
            'id' => 'abc-123', 'title' => 'Barbell Hip Thrust', 'muscleGroup' => 'glutes', 'equipment' => 'barbell',
            'instructions' => ['Step 1'], 'hasVideo' => true,
        ]]], 200)
        ->push(['data' => [[
            'id' => 'abc-123', 'title' => 'Updated Barbell Hip Thrust', 'muscleGroup' => 'glutes', 'equipment' => 'barbell',
            'instructions' => ['Step 1 updated'], 'hasVideo' => false,
        ]]], 200),
    ]);

    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->importSearch('ymove', new ProviderSearchCriteria);

    $exercise = Exercise::where('provider_exercise_id', 'abc-123')->first();
    $exercise->update([
        'contraindications' => [],
        'name_es' => 'Empuje de cadera con barra',
        'instructions_es' => ['Paso 1'],
        'important_points_es' => [],
    ]);

    // 3. activate() con contenido español ya generado no lo pierde.
    $reviewer = User::factory()->create();
    $exercise->activate($reviewer);
    $exercise->refresh();

    expect($exercise->is_active)->toBeTrue();
    expect($exercise->name_es)->toBe('Empuje de cadera con barra');
    expect($exercise->instructions_es)->toBe(['Paso 1']);
    expect($exercise->important_points_es)->toBe([]);

    // 4. toSnapshot() ya usa el español mientras está activo.
    expect($exercise->toSnapshot()['name'])->toBe('Empuje de cadera con barra');
    expect($exercise->toSnapshot()['instructions'])->toBe(['Paso 1']);

    // 1. un re-sync NO destruye el contenido en español, sin importar que
    // la metadata original cambie de verdad.
    $importer->importSearch('ymove', new ProviderSearchCriteria);
    $exercise->refresh();

    expect($exercise->name)->toBe('Updated Barbell Hip Thrust'); // original sí se refresca
    expect($exercise->name_es)->toBe('Empuje de cadera con barra'); // español, intacto
    expect($exercise->instructions_es)->toBe(['Paso 1']);
    expect($exercise->important_points_es)->toBe([]);
    expect($exercise->is_active)->toBeTrue(); // revisión humana también intacta

    // 5. provider_has_video siempre refleja al proveedor, sin relación con
    // is_active ni con el contenido curado — cambió a false en este
    // re-sync y el ejercicio sigue activo con su traducción intacta.
    expect($exercise->provider_has_video)->toBeFalse();
});

/**
 * Hito 9.3 — el comando ahora corre sobre ExerciseImporter::fullSync(),
 * que pagina de verdad usando la paginación real del proveedor (antes,
 * `importSearch()` solo pedía una página — riesgo real de desactivación
 * masiva con cualquier músculo de más de ~20 ejercicios, nunca ejecutado
 * en producción, corregido aquí). El fake necesita `pagination` para que
 * el comando sepa cuándo detenerse.
 */
it('deactivates exercises no longer returned by the provider, without deleting them', function () {
    $phase = ['before'];
    Http::fake(function ($request) use (&$phase) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $data = $phase[0] === 'before'
            ? [
                ['id' => 'keep-me', 'title' => 'Stays', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight', 'instructions' => ['Paso 1']],
                ['id' => 'remove-me', 'title' => 'Goes away', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight', 'instructions' => ['Paso 1']],
            ]
            : [['id' => 'keep-me', 'title' => 'Stays', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight', 'instructions' => ['Paso 1']]];

        return Http::response(['data' => $page === 1 ? $data : [], 'pagination' => ['page' => $page, 'totalPages' => 1]], 200);
    });

    Artisan::call('exercises:sync', ['provider' => 'ymove']);
    foreach (['keep-me', 'remove-me'] as $id) {
        $exercise = Exercise::where('provider_exercise_id', $id)->first();
        $exercise->update(['contraindications' => []]);
        $exercise->activate(User::factory()->create());
    }

    $phase[0] = 'after';
    Artisan::call('exercises:sync', ['provider' => 'ymove']);

    expect(Exercise::where('provider_exercise_id', 'keep-me')->first()->is_active)->toBeTrue();
    expect(Exercise::where('provider_exercise_id', 'remove-me')->first()->is_active)->toBeFalse();
    expect(Exercise::where('provider_exercise_id', 'remove-me')->exists())->toBeTrue(); // nunca borrado
});

it('only imports for the muscle focus requested, when the sync command is scoped', function () {
    Http::fake(function ($request) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $data = $page === 1 ? [['id' => 'glute-1', 'title' => 'Glute exercise', 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight', 'instructions' => ['Paso 1']]] : [];

        return Http::response(['data' => $data, 'pagination' => ['page' => $page, 'totalPages' => 1]], 200);
    });

    Artisan::call('exercises:sync', ['provider' => 'ymove', '--muscle' => ['glutes']]);

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'muscleGroup=glutes'));
    expect(Exercise::where('provider_exercise_id', 'glute-1')->exists())->toBeTrue();
});

/**
 * Hito 9.3 — importSelected(): importa EXACTAMENTE una lista aprobada de
 * provider_exercise_id, sin depender de find() (inviable en un catálogo
 * grande y paginado). Fake que distingue por página y por presencia de
 * `muscleGroup` en la query, para poder simular por separado la búsqueda
 * "con hint de músculo" y la pasada final sin filtro.
 */
function fakeYMovePagedCatalog(array $scopedByPage, array $unscopedByPage = []): void
{
    Http::fake(function ($request) use ($scopedByPage, $unscopedByPage) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $pages = isset($query['muscleGroup']) ? $scopedByPage : $unscopedByPage;

        return Http::response(['data' => $pages[$page] ?? []], 200);
    });
}

function ymoveStub(string $id, string $title = 'Some exercise'): array
{
    return ['id' => $id, 'title' => $title, 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight', 'instructions' => ['Paso 1']];
}

it('importSelected finds a target id on the first page', function () {
    fakeYMovePagedCatalog(scopedByPage: [], unscopedByPage: [1 => [ymoveStub('id-a')]]);

    $result = (new ExerciseImporter(new ProviderRegistry))->importSelected('ymove', ['id-a']);

    expect($result->imported)->toHaveCount(1);
    expect($result->notFound)->toBe([]);
    expect($result->pagesConsulted)->toBe(1);
    expect(Exercise::where('provider_exercise_id', 'id-a')->exists())->toBeTrue();
});

it('importSelected finds a target id only present on a later page', function () {
    fakeYMovePagedCatalog(scopedByPage: [], unscopedByPage: [
        1 => [ymoveStub('unrelated-1')],
        2 => [ymoveStub('id-b')],
    ]);

    $result = (new ExerciseImporter(new ProviderRegistry))->importSelected('ymove', ['id-b']);

    expect($result->imported)->toHaveCount(1);
    expect($result->imported->first()->provider_exercise_id)->toBe('id-b');
    expect($result->notFound)->toBe([]);
    expect($result->pagesConsulted)->toBe(2);
});

it('importSelected finds several ids spread across different pages, importing nothing else', function () {
    fakeYMovePagedCatalog(scopedByPage: [], unscopedByPage: [
        1 => [ymoveStub('id-a'), ymoveStub('extra-1')],
        2 => [ymoveStub('id-b')],
        3 => [ymoveStub('id-c'), ymoveStub('extra-2')],
    ]);

    $result = (new ExerciseImporter(new ProviderRegistry))->importSelected('ymove', ['id-a', 'id-b', 'id-c']);

    expect($result->imported->pluck('provider_exercise_id')->all())->toEqualCanonicalizing(['id-a', 'id-b', 'id-c']);
    expect($result->notFound)->toBe([]);
    expect(Exercise::whereIn('provider_exercise_id', ['extra-1', 'extra-2'])->exists())->toBeFalse();
    expect(Exercise::count())->toBe(3);
});

it('importSelected reports an id as not found after exhausting all applicable pages, without inventing it', function () {
    fakeYMovePagedCatalog(scopedByPage: [], unscopedByPage: [
        1 => [ymoveStub('something-else')],
        2 => [], // catálogo agotado
    ]);

    $result = (new ExerciseImporter(new ProviderRegistry))->importSelected('ymove', ['ghost-id']);

    expect($result->imported)->toHaveCount(0);
    expect($result->notFound)->toBe(['ghost-id']);
    expect(Exercise::where('provider_exercise_id', 'ghost-id')->exists())->toBeFalse();
});

it('importSelected never imports an exercise outside the target list, even when it appears in a consulted page', function () {
    fakeYMovePagedCatalog(scopedByPage: [], unscopedByPage: [1 => [ymoveStub('id-a'), ymoveStub('not-requested')]]);

    (new ExerciseImporter(new ProviderRegistry))->importSelected('ymove', ['id-a']);

    expect(Exercise::where('provider_exercise_id', 'not-requested')->exists())->toBeFalse();
});

it('importSelected treats a muscle hint as an optimization only, falling back to the unscoped catalog for correctness', function () {
    fakeYMovePagedCatalog(
        scopedByPage: [1 => []], // el hint de "glutes" no lo tiene — se agota de inmediato
        unscopedByPage: [1 => [ymoveStub('id-a')]], // pero SÍ está en el catálogo sin filtrar
    );

    $result = (new ExerciseImporter(new ProviderRegistry))->importSelected(
        'ymove',
        ['id-a'],
        [MuscleFocus::Glutes],
    );

    expect($result->imported)->toHaveCount(1);
    expect($result->notFound)->toBe([]);
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'muscleGroup=glutes'));
    Http::assertSent(fn ($request) => ! str_contains((string) $request->url(), 'muscleGroup'));
});

it('importSelected reports duplicate ids found in the input request, without importing them twice', function () {
    fakeYMovePagedCatalog(scopedByPage: [], unscopedByPage: [1 => [ymoveStub('id-a')]]);

    $result = (new ExerciseImporter(new ProviderRegistry))->importSelected('ymove', ['id-a', 'id-a']);

    expect($result->imported)->toHaveCount(1);
    expect($result->duplicatesInRequest)->toBe(['id-a']);
});

it('importSelected always requests catalog pages in browse mode, never requesting video', function () {
    fakeYMovePagedCatalog(scopedByPage: [], unscopedByPage: [1 => [ymoveStub('id-a')]]);

    (new ExerciseImporter(new ProviderRegistry))->importSelected('ymove', ['id-a']);

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=false'));
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=true'));
});
