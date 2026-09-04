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
