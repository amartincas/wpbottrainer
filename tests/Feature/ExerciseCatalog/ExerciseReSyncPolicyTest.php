<?php

use App\ExerciseCatalog\DTOs\ProviderSearchCriteria;
use App\ExerciseCatalog\Importer\ExerciseImporter;
use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Hito 9.3 (pre-import) — documento vivo de la política de re-sync de
 * `ExerciseImporter`, en un solo lugar, para que una modificación futura
 * del importer no rompa esta garantía por accidente. Cubre los 5 campos
 * relevantes juntos, en una sola corrida de sync-revisión-resync:
 *
 * | Campo               | En un re-sync...                                   |
 * |---------------------|------------------------------------------------------|
 * | instructions        | el proveedor SÍ puede actualizarlo                  |
 * | important_points    | el proveedor SÍ puede actualizarlo                  |
 * | common_mistakes     | se preserva el valor curado a mano, nunca se pisa   |
 * | breathing_cue       | se preserva el valor curado a mano, nunca se pisa   |
 * | contraindications   | se preserva el valor curado/revisado, nunca se pisa |
 *
 * `is_active` se incluye también porque es la consecuencia directa de que
 * `contraindications` se preserve — si se pisara, un ejercicio ya
 * aprobado por un humano podría reactivarse/desactivarse solo por un
 * re-sync, sin ninguna decisión humana de por medio.
 */
it('documents the exact re-sync policy: provider fields refresh, curated fields never do', function () {
    Http::fake(['exercise-api.ymove.app/*' => Http::sequence()
        ->push(['data' => [[
            'id' => 'abc-123',
            'title' => 'Barbell Hip Thrust',
            'muscleGroup' => 'glutes',
            'equipment' => 'barbell',
            'instructions' => ['Paso original 1'],
            'importantPoints' => ['Punto original 1'],
        ]]], 200)
        ->push(['data' => [[
            'id' => 'abc-123',
            'title' => 'Barbell Hip Thrust',
            'muscleGroup' => 'glutes',
            'equipment' => 'barbell',
            'instructions' => ['Paso ACTUALIZADO por el proveedor'],
            'importantPoints' => ['Punto ACTUALIZADO por el proveedor'],
        ]]], 200),
    ]);

    $importer = new ExerciseImporter(new ProviderRegistry);

    // 1. Import inicial — nunca activo, contraindications sin revisar.
    $importer->importSearch('ymove', new ProviderSearchCriteria);
    $exercise = Exercise::where('provider_exercise_id', 'abc-123')->first();

    expect($exercise->is_active)->toBeFalse();
    expect($exercise->contraindications)->toBeNull();

    // 2. Curación humana explícita — la ÚNICA forma de llegar a este estado.
    $reviewer = User::factory()->create();
    $exercise->update([
        'contraindications' => ['hernia discal'],
        'common_mistakes' => ['Arquear la espalda baja'],
        'breathing_cue' => 'Inhala al bajar, exhala al subir',
    ]);
    $exercise->activate($reviewer);

    expect($exercise->fresh()->is_active)->toBeTrue();

    // 3. Re-sync — el proveedor devuelve datos DISTINTOS para todo.
    $importer->importSearch('ymove', new ProviderSearchCriteria);
    $exercise = $exercise->fresh();

    // instructions / important_points: el proveedor SÍ pudo actualizarlos.
    expect($exercise->instructions)->toBe(['Paso ACTUALIZADO por el proveedor']);
    expect($exercise->important_points)->toBe(['Punto ACTUALIZADO por el proveedor']);

    // common_mistakes / breathing_cue: preservados, nunca pisados —
    // ningún proveedor auditado los provee, así que cualquier re-sync que
    // los tocara solo podría borrarlos, nunca mejorarlos.
    expect($exercise->common_mistakes)->toBe(['Arquear la espalda baja']);
    expect($exercise->breathing_cue)->toBe('Inhala al bajar, exhala al subir');

    // contraindications (+ is_active, su consecuencia directa): preservados
    // — un re-sync jamás debe reactivar/desactivar ni resetear una
    // revisión de seguridad ya hecha por un humano.
    expect($exercise->contraindications)->toBe(['hernia discal']);
    expect($exercise->is_active)->toBeTrue();
    expect($exercise->contraindications_reviewed_by)->toBe($reviewer->id);
});
