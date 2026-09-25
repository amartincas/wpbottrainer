<?php

use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\WorkoutExercise;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\ReplaceWorkoutExerciseService;

/**
 * Hito C (Sustitución de un ejercicio) — `ReplaceWorkoutExerciseService`.
 * Reutiliza `makeReadyContact()`/`trainingEngine()` de `TrainingEngineTest.php`
 * (mismo criterio ya usado por `TrainingEngineSelectReplacementTest.php`) —
 * este archivo debe ejecutarse siempre junto a `TrainingEngineTest.php`.
 *
 * Mismo criterio que `TrainingEngineSelectReplacementTest.php`: NUNCA se usa
 * `WorkoutExercise::factory()->create()` (crea un `Exercise` extra como
 * efecto secundario de su `definition()` real) — se usa
 * `selectReplacementTarget()`/`selectReplacementSession()`, ya definidas en
 * ese mismo archivo y compartidas aquí por el mismo mecanismo de Pest.
 *
 * NOTA SOBRE CONCURRENCIA (instrucción explícita del encargo): este
 * framework de tests no tiene un patrón fiable para simular dos peticiones
 * verdaderamente paralelas contra la misma fila (un solo proceso PHP, una
 * sola conexión de BD por test). No se intenta un test frágil para eso. En
 * su lugar, se cubre el efecto OBSERVABLE de la revalidación bajo lock: una
 * segunda invocación de `replace()` sobre un target que YA fue sustituido
 * (por la primera invocación, o por cualquier otra causa) debe ver el
 * estado ya actualizado y devolver `target_already_resolved`, sin crear un
 * segundo reemplazo — exactamente lo que el lock + revalidación garantizan
 * en producción cuando dos transacciones reales compiten por la misma fila.
 */
function replaceWorkoutExerciseService(): ReplaceWorkoutExerciseService
{
    return new ReplaceWorkoutExerciseService(trainingEngine());
}

it('replaces successfully: creates the replacement, marks the original superseded, links both', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest']);
    $candidate = Exercise::factory()->create(['muscle_group' => 'chest']);
    $target = selectReplacementTarget($session, $original, 2, WorkoutExercisePhase::Main);
    $originalSnapshot = $target->exercise_snapshot;
    $sessionSnapshotBefore = $session->prescription_context_snapshot;

    $outcome = replaceWorkoutExerciseService()->replace($target);

    expect($outcome->status)->toBe('replaced');
    expect($outcome->original->id)->toBe($target->id);
    expect($outcome->original->superseded_by_id)->toBe($outcome->replacement->id);
    expect($outcome->replacement->exercise_id)->toBe($candidate->id);
    expect($outcome->replacement->exercise_id)->not->toBe($original->id);

    // order/phase heredados, nunca cambiados.
    expect($outcome->replacement->order)->toBe(2);
    expect($outcome->replacement->phase)->toBe(WorkoutExercisePhase::Main);

    // Snapshot del original intacto (nunca mutado) — toEqual() (no toBe()):
    // el orden de claves de un JSON leído de MariaDB no está garantizado
    // igual al del array PHP original, aunque el contenido sea idéntico.
    expect($outcome->original->fresh()->exercise_snapshot)->toEqual($originalSnapshot);
    // Snapshot del reemplazo es propio y distinto.
    expect($outcome->replacement->exercise_snapshot)->not->toEqual($originalSnapshot);

    // prescription_context_snapshot de la sesión, intacto.
    expect($session->fresh()->prescription_context_snapshot)->toEqual($sessionSnapshotBefore);

    // La relación filtrada de la sesión ahora expone el REEMPLAZO, no el original.
    $activeIds = $session->fresh()->workoutExercises->pluck('id');
    expect($activeIds)->toContain($outcome->replacement->id);
    expect($activeIds)->not->toContain($target->id);
});

it('preserves delivered_at of the original; the replacement starts with delivered_at null', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'back']);
    Exercise::factory()->create(['muscle_group' => 'back']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);
    $target->update(['delivered_at' => now()->subMinutes(5)]);
    // Leído de vuelta de la BD (precisión ya truncada por la columna
    // datetime) — nunca comparado contra el valor en memoria con
    // microsegundos, que la columna nunca conserva.
    $deliveredAt = $target->fresh()->delivered_at;

    $outcome = replaceWorkoutExerciseService()->replace($target->fresh());

    expect($outcome->original->fresh()->delivered_at->eq($deliveredAt))->toBeTrue();
    expect($outcome->replacement->delivered_at)->toBeNull();
});

it('never persists a second row: WorkoutExercise count increases by exactly 1', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'arms']);
    Exercise::factory()->create(['muscle_group' => 'arms']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);

    $countBefore = WorkoutExercise::count();
    replaceWorkoutExerciseService()->replace($target);

    expect(WorkoutExercise::count())->toBe($countBefore + 1);
});

it('rejects a target that already has an ExerciseLog: target_already_resolved, nothing created', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'legs']);
    Exercise::factory()->create(['muscle_group' => 'legs']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);
    ExerciseLog::create(['workout_exercise_id' => $target->id, 'logged_at' => now()]);

    $countBefore = WorkoutExercise::count();
    $outcome = replaceWorkoutExerciseService()->replace($target->fresh());

    expect($outcome->status)->toBe('target_already_resolved');
    expect(WorkoutExercise::count())->toBe($countBefore);
    expect($target->fresh()->superseded_by_id)->toBeNull();
});

it('rejects a target already superseded: target_already_resolved, nothing created', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'shoulders']);
    $alreadyReplacedBy = Exercise::factory()->create(['muscle_group' => 'shoulders']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);
    $existingReplacement = selectReplacementTarget($session, $alreadyReplacedBy, 1, WorkoutExercisePhase::Main);
    $target->update(['superseded_by_id' => $existingReplacement->id]);

    $countBefore = WorkoutExercise::count();
    $outcome = replaceWorkoutExerciseService()->replace($target->fresh());

    expect($outcome->status)->toBe('target_already_resolved');
    expect(WorkoutExercise::count())->toBe($countBefore);
});

it('rejects a target whose session is not Scheduled: invalid_target_state', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Completed]);
    $original = Exercise::factory()->create(['muscle_group' => 'core']);
    Exercise::factory()->create(['muscle_group' => 'core']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);

    $countBefore = WorkoutExercise::count();
    $outcome = replaceWorkoutExerciseService()->replace($target->fresh());

    expect($outcome->status)->toBe('invalid_target_state');
    expect(WorkoutExercise::count())->toBe($countBefore);
});

it('concurrency (revalidación bajo lock): a second replace() on an already-resolved target never creates a second replacement', function () {
    $contact = makeReadyContact();
    $session = selectReplacementSession(['contact_id' => $contact->id]);
    $original = Exercise::factory()->create(['muscle_group' => 'chest']);
    Exercise::factory()->create(['muscle_group' => 'chest']);
    Exercise::factory()->create(['muscle_group' => 'chest']);
    $target = selectReplacementTarget($session, $original, 1, WorkoutExercisePhase::Main);

    $service = replaceWorkoutExerciseService();

    $first = $service->replace($target);
    expect($first->status)->toBe('replaced');

    $countAfterFirst = WorkoutExercise::count();

    // Segunda invocación con la MISMA referencia en memoria (simula una
    // petición que partió de un estado ya obsoleto) — replace() SIEMPRE
    // relee por ID bajo lockForUpdate(), nunca confía en el objeto recibido.
    $second = $service->replace($target);

    expect($second->status)->toBe('target_already_resolved');
    expect(WorkoutExercise::count())->toBe($countAfterFirst); // sin segundo reemplazo
});
