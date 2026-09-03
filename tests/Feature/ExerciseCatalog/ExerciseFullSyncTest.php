<?php

use App\ExerciseCatalog\Importer\ExerciseImporter;
use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use App\Models\User;
use App\Training\Enums\MuscleFocus;
use Illuminate\Support\Facades\Http;

/**
 * Hito 9.3 — ExerciseImporter::fullSync(): inventario COMPLETO del
 * catálogo del proveedor (nunca limitado a un lote curado), usando la
 * paginación REAL que YMove reporta. Garantía central: una respuesta
 * incompleta o un error de la API NUNCA se trata como "el ejercicio
 * desapareció" — solo un recorrido completo y limpio reconcilia bajas.
 */
function fakeYMoveFullCatalog(array $pagesByNumber, int $totalPages, ?int $failOnPage = null): void
{
    Http::fake(function ($request) use ($pagesByNumber, $totalPages, $failOnPage) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);

        if ($failOnPage !== null && $page === $failOnPage) {
            return Http::response('upstream error', 500);
        }

        $items = $pagesByNumber[$page] ?? [];

        return Http::response([
            'data' => $items,
            'pagination' => ['page' => $page, 'pageSize' => 20, 'total' => null, 'totalPages' => $totalPages],
        ], 200);
    });
}

function fullSyncStub(string $id, string $title = 'Some exercise', array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'title' => $title, 'muscleGroup' => 'glutes', 'equipment' => 'bodyweight',
        'instructions' => ['Paso 1'],
    ], $overrides);
}

it('walks every reported page using the real pagination metadata, not an assumed page count', function () {
    fakeYMoveFullCatalog([
        1 => [fullSyncStub('id-1')],
        2 => [fullSyncStub('id-2')],
        3 => [fullSyncStub('id-3')],
    ], totalPages: 3);

    $result = (new ExerciseImporter(new ProviderRegistry))->fullSync('ymove');

    expect($result->pagesProcessed)->toBe(3);
    expect($result->completedFully)->toBeTrue();
    expect($result->totalReceived)->toBe(3);
});

it('performs a full sync across the whole catalog and reports created/updated/unchanged accurately', function () {
    fakeYMoveFullCatalog([
        1 => [fullSyncStub('id-1', 'Exercise One'), fullSyncStub('id-2', 'Exercise Two')],
        2 => [fullSyncStub('id-3', 'Exercise Three')],
    ], totalPages: 2);

    $result = (new ExerciseImporter(new ProviderRegistry))->fullSync('ymove');

    expect($result->created)->toBe(3);
    expect($result->updated)->toBe(0);
    expect($result->unchanged)->toBe(0);
    expect($result->totalReceived)->toBe(3);
    expect(Exercise::where('provider', 'ymove')->count())->toBe(3);
});

it('is idempotent: running the same full sync twice creates nothing new and reports everything unchanged', function () {
    fakeYMoveFullCatalog([1 => [fullSyncStub('id-1'), fullSyncStub('id-2')]], totalPages: 1);

    $importer = new ExerciseImporter(new ProviderRegistry);
    $first = $importer->fullSync('ymove');
    $second = $importer->fullSync('ymove');

    expect($first->created)->toBe(2);
    expect($second->created)->toBe(0);
    expect($second->updated)->toBe(0);
    expect($second->unchanged)->toBe(2);
    expect(Exercise::where('provider', 'ymove')->count())->toBe(2); // nunca duplicados
});

it('reports an exercise as updated only when its provider-sourced metadata actually changed', function () {
    Http::fake(function ($request) {
        static $call = 0;
        $call++;
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);

        $title = $call <= 1 ? 'Original name' : 'Updated name';

        return Http::response([
            'data' => $page === 1 ? [fullSyncStub('id-1', $title)] : [],
            'pagination' => ['page' => $page, 'totalPages' => 1],
        ], 200);
    });

    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->fullSync('ymove');
    $result = $importer->fullSync('ymove');

    expect($result->updated)->toBe(1);
    expect($result->unchanged)->toBe(0);
    expect(Exercise::where('provider_exercise_id', 'id-1')->first()->name)->toBe('Updated name');
});

it('never duplicates a row for the same provider_exercise_id across pages or across runs', function () {
    fakeYMoveFullCatalog([1 => [fullSyncStub('id-1')]], totalPages: 1);

    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->fullSync('ymove');
    $importer->fullSync('ymove');
    $importer->fullSync('ymove');

    expect(Exercise::where('provider_exercise_id', 'id-1')->count())->toBe(1);
});

it('does not deactivate an exercise that is simply absent from an earlier page but present on a later one', function () {
    fakeYMoveFullCatalog([
        1 => [fullSyncStub('other-id')],
        2 => [fullSyncStub('id-on-page-2')],
    ], totalPages: 2);

    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->fullSync('ymove');
    Exercise::where('provider_exercise_id', 'id-on-page-2')->first()->update(['contraindications' => []]);
    Exercise::where('provider_exercise_id', 'id-on-page-2')->first()->activate(User::factory()->create());

    // Segunda corrida — el ejercicio sigue apareciendo en la página 2.
    $result = $importer->fullSync('ymove');

    expect($result->possiblyRemoved)->toBe([]);
    expect(Exercise::where('provider_exercise_id', 'id-on-page-2')->first()->is_active)->toBeTrue();
});

/**
 * Http::fake() llamado dos veces en el mismo test no reemplaza de forma
 * confiable la respuesta anterior (ver docs/TESTING_GUIDE.md) — para
 * simular una corrida 1 y una corrida 2 con catálogos distintos dentro
 * del mismo test se necesita UN solo fake cuyo comportamiento cambie
 * según una variable de "fase" capturada por referencia.
 */
function fakeYMoveFullCatalogByPhase(array &$phase, array $phasesConfig): void
{
    Http::fake(function ($request) use (&$phase, $phasesConfig) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $config = $phasesConfig[$phase[0]];

        if (($config['failOnPage'] ?? null) === $page) {
            return Http::response('upstream error', 500);
        }

        return Http::response([
            'data' => $config['pages'][$page] ?? [],
            'pagination' => ['page' => $page, 'totalPages' => $config['totalPages']],
        ], 200);
    });
}

it('never reconciles deactivations when the provider fails mid-sync — an incomplete response is never treated as removal', function () {
    $phase = ['run-1'];
    fakeYMoveFullCatalogByPhase($phase, [
        // Corrida 1: catálogo completo con 2 páginas.
        'run-1' => ['pages' => [1 => [fullSyncStub('id-1')], 2 => [fullSyncStub('id-2')]], 'totalPages' => 2],
        // Corrida 2: la API falla en la página 2 — id-2 nunca se vuelve a
        // ver en esta corrida, pero NO por haber desaparecido del proveedor.
        'run-2' => ['pages' => [1 => [fullSyncStub('id-1')]], 'totalPages' => 2, 'failOnPage' => 2],
    ]);

    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->fullSync('ymove');
    foreach (['id-1', 'id-2'] as $id) {
        $exercise = Exercise::where('provider_exercise_id', $id)->first();
        $exercise->update(['contraindications' => []]);
        $exercise->activate(User::factory()->create());
    }

    $phase[0] = 'run-2';
    $result = $importer->fullSync('ymove');

    expect($result->completedFully)->toBeFalse();
    expect($result->errorMessage)->not->toBeNull();
    expect($result->possiblyRemoved)->toBe([]);
    expect(Exercise::where('provider_exercise_id', 'id-2')->first()->is_active)->toBeTrue(); // NUNCA desactivado
});

it('deactivates, but never deletes, an exercise only after a full and clean traversal confirms it is gone', function () {
    $phase = ['run-1'];
    fakeYMoveFullCatalogByPhase($phase, [
        'run-1' => ['pages' => [1 => [fullSyncStub('id-1'), fullSyncStub('id-2')]], 'totalPages' => 1],
        // Corrida limpia y completa donde id-2 ya no aparece en ninguna página.
        'run-2' => ['pages' => [1 => [fullSyncStub('id-1')]], 'totalPages' => 1],
    ]);

    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->fullSync('ymove');
    foreach (['id-1', 'id-2'] as $id) {
        $exercise = Exercise::where('provider_exercise_id', $id)->first();
        $exercise->update(['contraindications' => []]);
        $exercise->activate(User::factory()->create());
    }

    $phase[0] = 'run-2';
    $result = $importer->fullSync('ymove');

    expect($result->completedFully)->toBeTrue();
    expect($result->possiblyRemoved)->toBe(['id-2']);
    expect(Exercise::where('provider_exercise_id', 'id-2')->first()->is_active)->toBeFalse();
    expect(Exercise::where('provider_exercise_id', 'id-2')->exists())->toBeTrue(); // nunca borrado
    expect(Exercise::where('provider_exercise_id', 'id-1')->first()->is_active)->toBeTrue(); // no afectado
});

it('never touches human curation (contraindications/common_mistakes/breathing_cue/is_active) during a full sync', function () {
    $phase = ['run-1'];
    fakeYMoveFullCatalogByPhase($phase, [
        'run-1' => ['pages' => [1 => [fullSyncStub('id-1', 'Original', ['instructions' => ['Paso original']])]], 'totalPages' => 1],
        'run-2' => ['pages' => [1 => [fullSyncStub('id-1', 'Updated', ['instructions' => ['Paso actualizado']])]], 'totalPages' => 1],
    ]);

    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->fullSync('ymove');

    $exercise = Exercise::where('provider_exercise_id', 'id-1')->first();
    $reviewer = User::factory()->create();
    $exercise->update(['contraindications' => ['hernia'], 'common_mistakes' => ['Curado'], 'breathing_cue' => 'Curado']);
    $exercise->activate($reviewer);

    $phase[0] = 'run-2';
    $importer->fullSync('ymove');

    $exercise = $exercise->fresh();
    expect($exercise->instructions)->toBe(['Paso actualizado']); // sí se refresca
    expect($exercise->contraindications)->toBe(['hernia']); // preservado
    expect($exercise->common_mistakes)->toBe(['Curado']); // preservado
    expect($exercise->breathing_cue)->toBe('Curado'); // preservado
    expect($exercise->is_active)->toBeTrue(); // preservado, nunca reseteado por el sync
});

/**
 * Hito 9.3 (sincronización completa) — `provider_has_video` persiste la
 * señal cruda del proveedor, refrescada en cada re-sync como cualquier
 * otro campo de metadata (nunca curada, puede cambiar libremente sin que
 * eso toque `is_active`).
 */
it('persists provider_has_video from the metadata and refreshes it on re-sync', function () {
    $phase = ['run-1'];
    Http::fake(function ($request) use (&$phase) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $hasVideo = $phase[0] === 'run-1';

        return Http::response([
            'data' => $page === 1 ? [fullSyncStub('id-1', overrides: ['hasVideo' => $hasVideo])] : [],
            'pagination' => ['page' => $page, 'totalPages' => 1],
        ], 200);
    });

    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->fullSync('ymove');

    $exercise = Exercise::where('provider_exercise_id', 'id-1')->first();
    expect($exercise->provider_has_video)->toBeTrue();

    $phase[0] = 'run-2';
    $importer->fullSync('ymove');

    expect($exercise->fresh()->provider_has_video)->toBeFalse();
    expect($exercise->fresh()->is_active)->toBeFalse(); // sin relación con is_active
});

it('never activates a newly discovered exercise automatically', function () {
    fakeYMoveFullCatalog([1 => [fullSyncStub('brand-new')]], totalPages: 1);

    (new ExerciseImporter(new ProviderRegistry))->fullSync('ymove');

    $exercise = Exercise::where('provider_exercise_id', 'brand-new')->first();
    expect($exercise->is_active)->toBeFalse();
    expect($exercise->contraindications)->toBeNull();
});

it('always requests every page of a full sync in browse mode, never requesting video', function () {
    fakeYMoveFullCatalog([
        1 => [fullSyncStub('id-1')],
        2 => [fullSyncStub('id-2')],
    ], totalPages: 2);

    (new ExerciseImporter(new ProviderRegistry))->fullSync('ymove');

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=false'));
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'includeVideos=true'));
});

it('never persists a provider video_url for any exercise created by a full sync', function () {
    fakeYMoveFullCatalog([1 => [fullSyncStub('id-1')]], totalPages: 1);

    (new ExerciseImporter(new ProviderRegistry))->fullSync('ymove');

    expect(Exercise::where('provider', 'ymove')->whereNotNull('video_url')->count())->toBe(0);
});

/**
 * Hito 9.3 — fullSync() acotado por MuscleFocus: usado por
 * `exercises:sync --muscle=X`, reemplazando el defecto real que tenía el
 * comando (una sola página, riesgo de desactivación masiva).
 */
function fakeYMoveByMuscle(array $pagesByMuscle, int $totalPages): void
{
    Http::fake(function ($request) use ($pagesByMuscle, $totalPages) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $muscle = $query['muscleGroup'] ?? null;
        $pages = $pagesByMuscle[$muscle] ?? [];

        return Http::response([
            'data' => $pages[$page] ?? [],
            'pagination' => ['page' => $page, 'totalPages' => $totalPages],
        ], 200);
    });
}

it('paginates through more than one page when the sync is scoped to a single muscle', function () {
    fakeYMoveByMuscle([
        'quads' => [1 => [fullSyncStub('q-1', 'Quad One', ['muscleGroup' => 'quads'])], 2 => [fullSyncStub('q-2', 'Quad Two', ['muscleGroup' => 'quads'])]],
    ], totalPages: 2);

    $result = (new ExerciseImporter(new ProviderRegistry))->fullSync('ymove', muscleFocus: MuscleFocus::Quads);

    expect($result->pagesProcessed)->toBe(2);
    expect($result->totalReceived)->toBe(2);
    expect(Exercise::whereIn('provider_exercise_id', ['q-1', 'q-2'])->count())->toBe(2);
});

it('never deactivates an active exercise of a different muscle when the sync is scoped', function () {
    // Glutes ya activo de una corrida previa (import selectivo o sync anterior).
    fakeYMoveByMuscle(['glutes' => [1 => [fullSyncStub('g-1', 'Glute One', ['muscleGroup' => 'glutes'])]]], totalPages: 1);
    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->fullSync('ymove', muscleFocus: MuscleFocus::Glutes);
    $glute = Exercise::where('provider_exercise_id', 'g-1')->first();
    $glute->update(['contraindications' => []]);
    $glute->activate(User::factory()->create());

    // Ahora un sync de "quads" que ni siquiera menciona a glutes.
    fakeYMoveByMuscle(['quads' => [1 => [fullSyncStub('q-1', 'Quad One', ['muscleGroup' => 'quads'])]]], totalPages: 1);
    $result = $importer->fullSync('ymove', muscleFocus: MuscleFocus::Quads);

    expect($result->possiblyRemoved)->toBe([]); // g-1 nunca estuvo en el alcance de este sync
    expect(Exercise::where('provider_exercise_id', 'g-1')->first()->is_active)->toBeTrue();
});

it('scoped sync still requests includeVideos=false and the correct muscleGroup filter', function () {
    fakeYMoveByMuscle(['back' => [1 => [fullSyncStub('b-1', 'Back One', ['muscleGroup' => 'back'])]]], totalPages: 1);

    (new ExerciseImporter(new ProviderRegistry))->fullSync('ymove', muscleFocus: MuscleFocus::Back);

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'muscleGroup=back')
        && str_contains((string) $request->url(), 'includeVideos=false'));
});

it('a mid-sync error while scoped to one muscle never deactivates anything in that muscle either', function () {
    $phase = ['run-1'];
    Http::fake(function ($request) use (&$phase) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);

        if ($phase[0] === 'run-2' && $page === 2) {
            return Http::response('upstream error', 500);
        }

        $data = match (true) {
            $phase[0] === 'run-1' && $page === 1 => [fullSyncStub('q-1', 'Quad One', ['muscleGroup' => 'quads'])],
            $phase[0] === 'run-1' && $page === 2 => [fullSyncStub('q-2', 'Quad Two', ['muscleGroup' => 'quads'])],
            $phase[0] === 'run-2' && $page === 1 => [fullSyncStub('q-1', 'Quad One', ['muscleGroup' => 'quads'])],
            default => [],
        };

        return Http::response(['data' => $data, 'pagination' => ['page' => $page, 'totalPages' => 2]], 200);
    });

    $importer = new ExerciseImporter(new ProviderRegistry);
    $importer->fullSync('ymove', muscleFocus: MuscleFocus::Quads);
    foreach (['q-1', 'q-2'] as $id) {
        $exercise = Exercise::where('provider_exercise_id', $id)->first();
        $exercise->update(['contraindications' => []]);
        $exercise->activate(User::factory()->create());
    }

    $phase[0] = 'run-2';
    $result = $importer->fullSync('ymove', muscleFocus: MuscleFocus::Quads);

    expect($result->completedFully)->toBeFalse();
    expect($result->possiblyRemoved)->toBe([]);
    expect(Exercise::where('provider_exercise_id', 'q-2')->first()->is_active)->toBeTrue(); // nunca desactivado
});

it('recognizes the previously-imported 53 by provider + provider_exercise_id instead of duplicating them', function () {
    fakeYMoveFullCatalog([1 => [fullSyncStub('already-imported', 'Curated Exercise')]], totalPages: 1);
    $importer = new ExerciseImporter(new ProviderRegistry);

    // Simula el import selectivo previo (Hito 9.3, los 53).
    $importer->fullSync('ymove');
    $exercise = Exercise::where('provider_exercise_id', 'already-imported')->first();
    $exercise->update(['contraindications' => []]);
    $exercise->activate(User::factory()->create());

    // El sync completo posterior lo vuelve a ver — debe reconocerlo, no duplicarlo.
    $result = $importer->fullSync('ymove');

    expect(Exercise::where('provider_exercise_id', 'already-imported')->count())->toBe(1);
    expect($result->created)->toBe(0);
    expect(Exercise::where('provider_exercise_id', 'already-imported')->first()->is_active)->toBeTrue();
});
