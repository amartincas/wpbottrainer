<?php

use App\Models\Exercise;
use App\Models\WorkoutExercise;
use App\Training\Enums\MuscleFocus;
use Illuminate\Support\Facades\Http;

/**
 * Hito Backfill controlado de Exercise Normalization — 100% local, cero
 * llamadas reales al proveedor (verificado con Http::fake() + assert en
 * cada test). Dry-run es el comportamiento POR DEFECTO — hace falta
 * `--apply` explícito para escribir algo, y `--include-active` explícito
 * además de eso para tocar un ejercicio Active.
 */
it('fails clearly for an unknown provider, without touching any data', function () {
    $this->artisan('exercises:backfill-normalization', ['provider' => 'not_a_real_provider'])
        ->assertFailed();
});

it('dry-run (no --apply) reports the equipment/muscle changes but writes nothing at all', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'equipment_needed' => [], // resultado antiguo, incorrecto
        'primary_muscle' => null, // resultado antiguo, incorrecto
        'provider_metadata' => ['equipment' => 'rings', 'muscleGroup' => 'quadriceps'],
    ]);

    $this->artisan('exercises:backfill-normalization', ['provider' => 'ymove'])
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful();

    // Cero escrituras — el dry-run nunca persiste nada.
    expect($exercise->fresh()->equipment_needed)->toBe([]);
    expect($exercise->fresh()->primary_muscle)->toBeNull();
    Http::assertNothingSent();
});

it('--apply writes the recomputed equipment_needed/primary_muscle/secondary_muscles for a pending_review exercise', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'is_active' => false,
        'equipment_needed' => [],
        'primary_muscle' => null,
        'secondary_muscles' => null,
        'provider_metadata' => [
            'equipment' => 'rings',
            'muscleGroup' => 'quadriceps',
            'secondaryMuscles' => ['triceps', 'lats', 'serratus_anterior'],
        ],
    ]);

    $this->artisan('exercises:backfill-normalization', ['provider' => 'ymove', '--apply' => true])
        ->assertSuccessful();

    $fresh = $exercise->fresh();
    expect($fresh->equipment_needed)->toBe(['rings']);
    expect($fresh->primary_muscle)->toBe(MuscleFocus::Quads);
    expect($fresh->secondary_muscles)->toBe(['triceps', 'back']); // 'serratus_anterior' se filtra, no se inventa
});

it('never writes to an Active exercise unless --include-active is also passed', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'is_active' => true,
        'contraindications' => [],
        'equipment_needed' => [],
        'primary_muscle' => null,
        'provider_metadata' => ['equipment' => 'bosu', 'muscleGroup' => 'lats'],
    ]);

    // --apply solo, SIN --include-active: se reporta, pero no se escribe.
    $this->artisan('exercises:backfill-normalization', ['provider' => 'ymove', '--apply' => true])
        ->expectsOutputToContain('Active excluido de la escritura')
        ->assertSuccessful();

    expect($exercise->fresh()->equipment_needed)->toBe([]);
    expect($exercise->fresh()->primary_muscle)->toBeNull();

    // Ahora con --include-active: sí se escribe.
    $this->artisan('exercises:backfill-normalization', ['provider' => 'ymove', '--apply' => true, '--include-active' => true])
        ->assertSuccessful();

    expect($exercise->fresh()->equipment_needed)->toBe(['bosu']);
    expect($exercise->fresh()->primary_muscle)->toBe(MuscleFocus::Back);
});

it('tallies a genuinely NEW equipment value gained (battle rope, previously unmapped -> [])', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'equipment_needed' => [], // resultado del mapa VIEJO, que no conocía 'battle rope'
        'provider_metadata' => ['equipment' => 'battle rope'],
    ]);

    $this->artisan('exercises:backfill-normalization', ['provider' => 'ymove', '--apply' => true])
        ->expectsOutputToContain('battle_rope => 1')
        ->assertSuccessful();

    expect($exercise->fresh()->equipment_needed)->toBe(['battle_rope']);
});

it('genuinely counts a raw value with no mapping at all as moving into Unsupported', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'equipment_needed' => [],
        'provider_metadata' => ['equipment' => 'a completely novel gadget'],
    ]);

    $this->artisan('exercises:backfill-normalization', ['provider' => 'ymove', '--apply' => true])
        ->expectsOutputToContain('equipment -> Unsupported (antes no lo era): 1')
        ->assertSuccessful();

    expect($exercise->fresh()->equipment_needed)->toBe(['unsupported']);
});

it('counts primary_muscle recovered (previously NULL, now a real focus)', function () {
    Http::fake();

    Exercise::factory()->fromProvider('ymove')->create([
        'primary_muscle' => null,
        'provider_metadata' => ['equipment' => 'bodyweight', 'muscleGroup' => 'lats'],
    ]);

    $this->artisan('exercises:backfill-normalization', ['provider' => 'ymove', '--apply' => true])
        ->expectsOutputToContain('primary_muscle recuperado (antes NULL, ahora con foco): 1')
        ->assertSuccessful();
});

it('leaves an exercise whose muscleGroup is genuinely unmapped or invalid still at NULL — never fabricated', function () {
    Http::fake();

    $stillUnsupported = Exercise::factory()->fromProvider('ymove')->create([
        'primary_muscle' => null,
        'provider_metadata' => ['equipment' => 'bodyweight', 'muscleGroup' => 'legs'], // no forzado
    ]);
    $invalidData = Exercise::factory()->fromProvider('ymove')->create([
        'primary_muscle' => null,
        'provider_metadata' => ['equipment' => 'kettlebell', 'muscleGroup' => 'ketllebell'], // dato inválido
    ]);

    $this->artisan('exercises:backfill-normalization', ['provider' => 'ymove'])->assertSuccessful();

    expect($stillUnsupported->fresh()->primary_muscle)->toBeNull();
    expect($invalidData->fresh()->primary_muscle)->toBeNull();
});

it('is idempotent: a second dry-run after --apply reports zero further changes', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'equipment_needed' => [],
        'primary_muscle' => null,
        'provider_metadata' => ['equipment' => 'rings', 'muscleGroup' => 'quadriceps'],
    ]);

    $this->artisan('exercises:backfill-normalization', ['provider' => 'ymove', '--apply' => true])->assertSuccessful();
    expect($exercise->fresh()->equipment_needed)->toBe(['rings']);

    $this->artisan('exercises:backfill-normalization', ['provider' => 'ymove'])
        ->expectsOutputToContain('ejercicios con al menos un cambio: 0')
        ->assertSuccessful();

    expect($exercise->fresh()->equipment_needed)->toBe(['rings']); // sin cambios
});

it('never touches a different provider, nor workout_exercises/exercise_snapshot of any historical record', function () {
    Http::fake();

    $otherProvider = Exercise::factory()->create(['provider' => null, 'equipment_needed' => ['barbell']]);

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'equipment_needed' => [],
        'provider_metadata' => ['equipment' => 'rings'],
    ]);
    $workoutExercise = WorkoutExercise::factory()->create([
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
    ]);
    $snapshotBefore = $workoutExercise->exercise_snapshot;
    $updatedAtBefore = $workoutExercise->updated_at;

    $this->artisan('exercises:backfill-normalization', ['provider' => 'ymove', '--apply' => true])->assertSuccessful();

    expect($otherProvider->fresh()->equipment_needed)->toBe(['barbell']);
    expect($exercise->fresh()->equipment_needed)->toBe(['rings']);
    expect($workoutExercise->fresh()->exercise_snapshot)->toEqual($snapshotBefore);
    expect($workoutExercise->fresh()->updated_at->eq($updatedAtBefore))->toBeTrue();
});
