<?php

use App\Models\Exercise;
use Illuminate\Support\Facades\Http;

/**
 * Hito 9.3 (post-deploy) — 100% local, cero llamadas reales a YMove
 * (verificado explícitamente en cada test con Http::fake() vacío/assert).
 */
it('re-derives equipment_needed for an exercise stuck with the old incomplete map, without calling YMove', function () {
    Http::fake(); // cualquier llamada de red aquí sería un fallo de diseño

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'equipment_needed' => [], // resultado incorrecto del mapa viejo
        'provider_metadata' => ['equipment' => 'smith machine'],
    ]);

    $this->artisan('exercises:backfill-equipment', ['provider' => 'ymove'])
        ->assertSuccessful();

    expect($exercise->fresh()->equipment_needed)->toBe(['smith_machine']);
    Http::assertNothingSent();
});

it('leaves an exercise unchanged (and does not count it) when its equipment was already correct', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'equipment_needed' => ['barbell'],
        'provider_metadata' => ['equipment' => 'barbell'],
    ]);

    $this->artisan('exercises:backfill-equipment', ['provider' => 'ymove'])
        ->expectsOutputToContain('0 de')
        ->assertSuccessful();

    expect($exercise->fresh()->equipment_needed)->toBe(['barbell']);
});

it('never touches exercises from a different provider', function () {
    Http::fake();

    $other = Exercise::factory()->create(['provider' => null, 'equipment_needed' => ['barbell']]);

    $this->artisan('exercises:backfill-equipment', ['provider' => 'ymove'])->assertSuccessful();

    expect($other->fresh()->equipment_needed)->toBe(['barbell']);
});

it('fails clearly for an unknown provider, without touching any data', function () {
    $this->artisan('exercises:backfill-equipment', ['provider' => 'not_a_real_provider'])
        ->assertFailed();
});

/**
 * Hito 15.2 — el backfill ahora también corrige el caso real
 * `'bodyweight' => []` mal derivado para ejercicios que en realidad exigen
 * un aparato/superficie (contact_id=28, exercise_id=710 en staging).
 */
it('refines a previously mis-normalized bodyweight exercise that actually requires a pull-up bar (real case: exercise_id=710)', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name' => 'Pull Up (Neutral Grip)',
        'equipment_needed' => [], // resultado incorrecto anterior a Hito 15.2
        'provider_metadata' => [
            'equipment' => 'bodyweight',
            'title' => 'Pull Up (Neutral Grip)',
            'description' => 'Starting position: Hang from pull-up bar with palms facing each other, shoulder-width apart.',
            'instructions' => ['Grip the bar with palms facing each other'],
        ],
    ]);

    $this->artisan('exercises:backfill-equipment', ['provider' => 'ymove'])
        ->expectsOutputToContain('1 de')
        ->assertSuccessful();

    expect($exercise->fresh()->equipment_needed)->toBe(['pull_up_bar']);
    Http::assertNothingSent();
});

it('refines a previously mis-normalized bodyweight exercise that actually requires a bench (real case: exercise_id=81)', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name' => 'Bench Dips',
        'equipment_needed' => [],
        'provider_metadata' => [
            'equipment' => 'bodyweight',
            'title' => 'Bench Dips',
            'description' => 'Starting position: Sit on the edge of a bench with hands gripping the edge beside your hips.',
        ],
    ]);

    $this->artisan('exercises:backfill-equipment', ['provider' => 'ymove'])
        ->assertSuccessful();

    expect($exercise->fresh()->equipment_needed)->toBe(['bench']);
});

it('leaves a genuine bodyweight exercise at no-equipment, unchanged, when no apparatus signal is present', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name' => 'Bodyweight Squat',
        'equipment_needed' => [],
        'provider_metadata' => [
            'equipment' => 'bodyweight',
            'title' => 'Bodyweight Squat',
            'description' => 'Stand with feet shoulder-width apart, toes slightly turned out, chest up and core braced.',
        ],
    ]);

    $this->artisan('exercises:backfill-equipment', ['provider' => 'ymove'])
        ->expectsOutputToContain('0 de')
        ->assertSuccessful();

    expect($exercise->fresh()->equipment_needed)->toBe([]);
});

it('is idempotent: running it again after the bodyweight refinement reports zero further changes', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name' => 'Pull Up (Neutral Grip)',
        'equipment_needed' => [],
        'provider_metadata' => [
            'equipment' => 'bodyweight',
            'title' => 'Pull Up (Neutral Grip)',
            'description' => 'Hang from pull-up bar with palms facing each other.',
        ],
    ]);

    $this->artisan('exercises:backfill-equipment', ['provider' => 'ymove'])
        ->expectsOutputToContain('1 de')
        ->assertSuccessful();
    expect($exercise->fresh()->equipment_needed)->toBe(['pull_up_bar']);

    // Segunda corrida sobre el mismo dato ya corregido: cero cambios.
    $this->artisan('exercises:backfill-equipment', ['provider' => 'ymove'])
        ->expectsOutputToContain('0 de')
        ->assertSuccessful();
    expect($exercise->fresh()->equipment_needed)->toBe(['pull_up_bar']);
});

it('never touches workout_exercises when the live catalog is corrected (historical prescriptions stay untouched)', function () {
    Http::fake();

    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name' => 'Pull Up (Neutral Grip)',
        'equipment_needed' => [], // dato ya prescrito con el valor incorrecto
        'provider_metadata' => [
            'equipment' => 'bodyweight',
            'title' => 'Pull Up (Neutral Grip)',
            'description' => 'Hang from pull-up bar with palms facing each other.',
        ],
    ]);

    // Nota: equipment_needed no forma parte de exercise_snapshot en
    // absoluto (ver Exercise::toSnapshot()) — este test verifica algo más
    // amplio: que el comando de backfill no toca `workout_exercises` de
    // ninguna forma, ni siquiera sus timestamps, aunque corrija el
    // Exercise del que ese registro histórico proviene.
    $workoutExercise = \App\Models\WorkoutExercise::factory()->create([
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
    ]);
    $updatedAtBefore = $workoutExercise->updated_at;
    $snapshotBefore = $workoutExercise->exercise_snapshot;

    $this->artisan('exercises:backfill-equipment', ['provider' => 'ymove'])->assertSuccessful();

    expect($exercise->fresh()->equipment_needed)->toBe(['pull_up_bar']);
    // toEqual() (no toBe()): la columna JSON nativa de MySQL puede
    // reordenar claves al releer desde disco — comparamos valores, no
    // orden de claves, que no es algo que este comando controle ni le
    // concierna.
    expect($workoutExercise->fresh()->exercise_snapshot)->toEqual($snapshotBefore);
    expect($workoutExercise->fresh()->updated_at->eq($updatedAtBefore))->toBeTrue();
});
