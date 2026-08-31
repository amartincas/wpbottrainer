<?php

use App\Models\Exercise;
use App\Models\WorkoutExercise;

/**
 * Hito 4, requisito obligatorio: una WorkoutExercise ya entregada al usuario
 * debe conservar exactamente lo que se le mostró, sin importar qué le pase
 * a Exercise después. Ver docs/DECISIONS.md.
 */

it('keeps its exercise_snapshot unchanged after the live Exercise catalog is edited', function () {
    $exercise = Exercise::factory()->create([
        'name' => 'Sentadilla',
        'instructions' => 'Instrucciones originales.',
        'video_url' => 'https://videos.example.test/squat-v1.mp4',
        'muscle_group' => 'legs',
    ]);

    $workoutExercise = WorkoutExercise::factory()->create([
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
    ]);

    $originalSnapshot = $workoutExercise->fresh()->exercise_snapshot;

    // El catálogo se corrige/mejora después de haber entregado la sesión.
    $exercise->update([
        'name' => 'Sentadilla profunda (corregido)',
        'instructions' => 'Instrucciones completamente reescritas.',
        'video_url' => 'https://videos.example.test/squat-v2.mp4',
        'muscle_group' => 'legs',
    ]);

    $reloaded = $workoutExercise->fresh();

    expect($reloaded->exercise_snapshot)->toBe($originalSnapshot);
    expect($reloaded->exercise_snapshot['name'])->toBe('Sentadilla');
    expect($reloaded->exercise_snapshot['instructions'])->toBe('Instrucciones originales.');
    expect($reloaded->exercise_snapshot['video_url'])->toBe('https://videos.example.test/squat-v1.mp4');

    // El catálogo vigente sí refleja el cambio — exercise_id es solo
    // trazabilidad/analítica, nunca la fuente de lo históricamente entregado.
    expect($reloaded->exercise->name)->toBe('Sentadilla profunda (corregido)');
});

it('survives the deletion of its referenced Exercise without losing historical content', function () {
    $exercise = Exercise::factory()->create(['name' => 'Burpee']);

    $workoutExercise = WorkoutExercise::factory()->create([
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
    ]);

    $exercise->delete();

    $reloaded = $workoutExercise->fresh();

    expect($reloaded)->not->toBeNull();
    expect($reloaded->exercise_id)->toBeNull(); // nullOnDelete, nunca cascade
    expect($reloaded->exercise_snapshot['name'])->toBe('Burpee');
});

it('never overwrites prescribed fields once created', function () {
    $workoutExercise = WorkoutExercise::factory()->create([
        'prescribed_sets' => 3,
        'prescribed_reps' => 10,
        'prescribed_load' => null,
    ]);

    $original = $workoutExercise->only(['prescribed_sets', 'prescribed_reps', 'prescribed_load', 'exercise_snapshot']);

    // Nada en el dominio debe volver a escribir sobre esta fila — se
    // verifica releyéndola tal cual quedó al crearse.
    $reloaded = WorkoutExercise::find($workoutExercise->id);

    expect($reloaded->only(['prescribed_sets', 'prescribed_reps', 'prescribed_load']))
        ->toBe(['prescribed_sets' => 3, 'prescribed_reps' => 10, 'prescribed_load' => null]);
    expect($reloaded->exercise_snapshot)->toEqualCanonicalizing($original['exercise_snapshot']);
});
