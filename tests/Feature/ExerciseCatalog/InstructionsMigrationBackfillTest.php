<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 9.2 — valida el riesgo real de la migración
 * `2026_09_04_000002_add_technique_fields_to_exercises_table`: el único
 * `Exercise` real de producción ("Plancha", demo de Hito 7) tenía
 * `instructions` como una oración de texto plano, NO JSON válido. Cambiar
 * el tipo de columna directamente la habría corrompido/rechazado.
 *
 * Este test reproduce exactamente ese escenario — revierte la columna a
 * `text` (su forma pre-migración), inserta una fila con texto plano tal
 * como la tenía producción, y ejecuta el MISMO backfill SQL que la
 * migración real usa, verificando que el dato sobrevive como un array
 * JSON de un elemento en vez de perderse o romper la migración.
 */
it('preserves an existing plain-text instructions value as a one-element JSON array during the migration', function () {
    Schema::table('exercises', function ($table) {
        $table->text('instructions')->nullable()->change();
    });

    DB::table('exercises')->insert([
        'name' => 'Plancha (demo E2E)',
        'slug' => 'plancha-demo-e2e-backfill-test',
        'instructions' => 'Mantén el cuerpo recto apoyado en antebrazos y puntas de los pies.',
        'muscle_group' => 'core',
        'difficulty_level' => 'beginner',
        'tracking_type' => 'time_based',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Mismo statement exacto que la migración real ejecuta antes de
    // cambiar el tipo de columna — ver database/migrations/
    // 2026_09_04_000002_add_technique_fields_to_exercises_table.php
    DB::statement(
        'UPDATE exercises SET instructions = JSON_ARRAY(instructions) '.
        'WHERE instructions IS NOT NULL AND JSON_VALID(instructions) = 0'
    );

    Schema::table('exercises', function ($table) {
        $table->json('instructions')->nullable()->change();
    });

    $exercise = App\Models\Exercise::where('slug', 'plancha-demo-e2e-backfill-test')->first();

    expect($exercise->instructions)->toBe(['Mantén el cuerpo recto apoyado en antebrazos y puntas de los pies.']);
});

it('does not double-wrap an instructions value that is already valid JSON', function () {
    Schema::table('exercises', function ($table) {
        $table->text('instructions')->nullable()->change();
    });

    DB::table('exercises')->insert([
        'name' => 'Already JSON',
        'slug' => 'already-json-backfill-test',
        'instructions' => json_encode(['Paso 1', 'Paso 2']),
        'muscle_group' => 'core',
        'difficulty_level' => 'beginner',
        'tracking_type' => 'reps_and_load',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement(
        'UPDATE exercises SET instructions = JSON_ARRAY(instructions) '.
        'WHERE instructions IS NOT NULL AND JSON_VALID(instructions) = 0'
    );

    Schema::table('exercises', function ($table) {
        $table->json('instructions')->nullable()->change();
    });

    $exercise = App\Models\Exercise::where('slug', 'already-json-backfill-test')->first();

    expect($exercise->instructions)->toBe(['Paso 1', 'Paso 2']);
});
