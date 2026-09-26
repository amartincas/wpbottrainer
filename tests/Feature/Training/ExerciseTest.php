<?php

use App\Models\Exercise;
use App\Models\ExerciseVideoAccess;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\LoadModality;
use App\Training\Enums\TrackingType;
use App\Training\Enums\WorkoutExercisePhase;

it('casts array and boolean fields correctly', function () {
    $exercise = Exercise::factory()->create([
        'equipment_needed' => ['barbell'],
        'contraindications' => ['knee'],
        'is_active' => true,
    ]);

    $fresh = $exercise->fresh();

    expect($fresh->equipment_needed)->toBe(['barbell']);
    expect($fresh->contraindications)->toBe(['knee']);
    expect($fresh->is_active)->toBeTrue();
    expect($fresh->tracking_type)->toBe(TrackingType::RepsAndLoad);
});

it('can be retired from the active catalog without being deleted', function () {
    $exercise = Exercise::factory()->inactive()->create();

    expect($exercise->is_active)->toBeFalse();
    expect(Exercise::find($exercise->id))->not->toBeNull();
});

it('produces a snapshot with exactly the fields shown to the user', function () {
    $exercise = Exercise::factory()->create([
        'name' => 'Sentadilla',
        'instructions' => 'Baja controlando la rodilla.',
        'video_url' => 'https://videos.example.test/squat.mp4',
        'muscle_group' => 'legs',
    ]);

    $snapshot = $exercise->toSnapshot();

    // Hito D (fase D4) — forma aditiva: tracking_type/load_modality se
    // suman a las claves ya existentes (default del factory: RepsAndLoad /
    // sin clasificar).
    expect($snapshot)->toBe([
        'name' => 'Sentadilla',
        'instructions' => 'Baja controlando la rodilla.',
        'important_points' => null,
        'common_mistakes' => null,
        'breathing_cue' => null,
        'video_url' => 'https://videos.example.test/squat.mp4',
        'muscle_group' => 'legs',
        'primary_muscle' => null,
        'secondary_muscles' => null,
        'provider' => null,
        'provider_exercise_id' => null,
        'tracking_type' => 'reps_and_load',
        'load_modality' => null,
    ]);
});

// ── Hito 9.1: capa multi-proveedor ──────────────────────────────────────

it('rejects saving an Exercise that has both a provider and a direct video_url', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->make(['video_url' => 'https://ymove.example/video.mp4']);

    expect(fn () => $exercise->save())->toThrow(DomainException::class);
});

it('allows a manual exercise (no provider) to keep its own stable video_url', function () {
    $exercise = Exercise::factory()->create(['video_url' => 'https://videos.example.test/demo.mp4']);

    expect($exercise->provider)->toBeNull();
    expect($exercise->fresh()->video_url)->toBe('https://videos.example.test/demo.mp4');
});

it('starts a provider-imported exercise as inactive with unreviewed contraindications', function () {
    $exercise = Exercise::factory()->fromProvider('ymove', 'abc-123')->create();

    expect($exercise->is_active)->toBeFalse();
    expect($exercise->contraindications)->toBeNull();
    expect($exercise->video_url)->toBeNull();
});

it('refuses to activate an exercise whose contraindications were never reviewed', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create();
    $reviewer = User::factory()->create();

    expect(fn () => $exercise->activate($reviewer))->toThrow(DomainException::class);
    expect($exercise->fresh()->is_active)->toBeFalse();
});

it('activates an exercise only after a human explicitly confirms contraindications, even an empty list', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create(['contraindications' => null]);
    $reviewer = User::factory()->create();

    // Un humano revisa y confirma que no hay ninguna conocida — [] es una
    // respuesta válida y completa, nunca asumida automáticamente.
    $exercise->update(['contraindications' => []]);
    $exercise->activate($reviewer);

    $fresh = $exercise->fresh();
    expect($fresh->is_active)->toBeTrue();
    expect($fresh->contraindications_reviewed_by)->toBe($reviewer->id);
    expect($fresh->contraindications_reviewed_at)->not->toBeNull();
});

// ── Hito 9.2: técnica de ejecución — asimetría de curación, punto 7 ─────

it('blocks activation when contraindications or instructions are missing, but never for the optional technique fields', function () {
    $reviewer = User::factory()->create();

    // contraindications = null → NO puede activarse.
    $missingContraindications = Exercise::factory()->fromProvider('ymove')->create([
        'contraindications' => null,
        'instructions' => ['Paso 1'],
    ]);
    expect(fn () => $missingContraindications->activate($reviewer))->toThrow(DomainException::class);

    // instructions = [] → NO puede activarse (aunque contraindications ya
    // esté revisado).
    $missingInstructions = Exercise::factory()->fromProvider('ymove')->create([
        'contraindications' => [],
        'instructions' => [],
    ]);
    expect(fn () => $missingInstructions->activate($reviewer))->toThrow(DomainException::class);

    // important_points / common_mistakes / breathing_cue en null → SÍ
    // puede activarse — son contenido opcional, nunca bloquean.
    $readyDespiteMissingTechnique = Exercise::factory()->fromProvider('ymove')->create([
        'contraindications' => [],
        'instructions' => ['Paso 1'],
        'important_points' => null,
        'common_mistakes' => null,
        'breathing_cue' => null,
    ]);
    $readyDespiteMissingTechnique->activate($reviewer);

    expect($readyDespiteMissingTechnique->fresh()->is_active)->toBeTrue();
});

// ── Hito 9.3 (fix post-E2E): contenido en español, preferido en snapshot ──

it('snapshot uses the original English content when no Spanish translation exists yet', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name' => 'Squat',
        'instructions' => ['Bend your knees.'],
        'important_points' => ['Keep your back straight.'],
        'name_es' => null,
        'instructions_es' => null,
        'important_points_es' => null,
    ]);

    $snapshot = $exercise->toSnapshot();

    expect($snapshot['name'])->toBe('Squat');
    expect($snapshot['instructions'])->toBe(['Bend your knees.']);
    expect($snapshot['important_points'])->toBe(['Keep your back straight.']);
});

it('snapshot prefers the curated Spanish content once generated, without losing the original English columns', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name' => 'Squat',
        'instructions' => ['Bend your knees.'],
        'important_points' => ['Keep your back straight.'],
        'name_es' => 'Sentadilla',
        'instructions_es' => ['Dobla las rodillas.'],
        'important_points_es' => ['Mantén la espalda recta.'],
    ]);

    $snapshot = $exercise->toSnapshot();

    expect($snapshot['name'])->toBe('Sentadilla');
    expect($snapshot['instructions'])->toBe(['Dobla las rodillas.']);
    expect($snapshot['important_points'])->toBe(['Mantén la espalda recta.']);
    // El original nunca se pierde ni se sobreescribe.
    expect($exercise->fresh()->name)->toBe('Squat');
    expect($exercise->fresh()->instructions)->toBe(['Bend your knees.']);
});

it('snapshot treats an explicitly empty important_points_es ([]) as a real translated answer, not as "not translated yet"', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'important_points' => ['English point.'],
        'important_points_es' => [],
    ]);

    expect($exercise->toSnapshot()['important_points'])->toBe([]);
});

// ── Hito 9.3 (post-deploy): videoValidated() y scopeWithReviewStatus() ──

it('videoValidated() is false when no video access has ever been recorded for this exercise', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create();

    expect($exercise->videoValidated())->toBeFalse();
});

it('videoValidated() is true once a video access exists, and stays true even if provider_has_video is false', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create(['provider_has_video' => false]);

    ExerciseVideoAccess::create([
        'exercise_id' => $exercise->id,
        'provider' => 'ymove',
        'provider_exercise_id' => $exercise->provider_exercise_id,
        'variant' => 'default',
        'resolved_at' => now(),
    ]);

    // Deliberadamente independiente de provider_has_video — son preguntas
    // distintas (lo que el proveedor dice vs. lo que nosotros comprobamos).
    expect($exercise->fresh()->videoValidated())->toBeTrue();
});

it('videoValidated() works correctly whether or not the relation was eager-loaded', function () {
    $exercise = Exercise::factory()->fromProvider('ymove')->create();
    ExerciseVideoAccess::create([
        'exercise_id' => $exercise->id,
        'provider' => 'ymove',
        'provider_exercise_id' => $exercise->provider_exercise_id,
        'variant' => 'default',
        'resolved_at' => now(),
    ]);

    $lazy = Exercise::find($exercise->id);
    $eager = Exercise::with('videoAccesses')->find($exercise->id);

    expect($lazy->videoValidated())->toBeTrue();
    expect($eager->videoValidated())->toBeTrue();
});

it('scopeWithReviewStatus filters exactly like reviewStatus() computes, for all three states', function () {
    $pending = Exercise::factory()->fromProvider('ymove')->create();
    $active = Exercise::factory()->fromProvider('ymove')->reviewedAndActive()->create();
    $inactive = Exercise::factory()->fromProvider('ymove')->reviewedAndActive()->create();
    $inactive->update(['is_active' => false]);

    expect(Exercise::withReviewStatus('pending_review')->pluck('id'))->toContain($pending->id)
        ->not->toContain($active->id)->not->toContain($inactive->id);
    expect(Exercise::withReviewStatus('active')->pluck('id'))->toContain($active->id)
        ->not->toContain($pending->id)->not->toContain($inactive->id);
    expect(Exercise::withReviewStatus('inactive')->pluck('id'))->toContain($inactive->id)
        ->not->toContain($pending->id)->not->toContain($active->id);
});

// ── Hito D (diseño formal v2 aprobado, fase D1): LoadModality ────────────
// Alcance ESTRICTO de esta fase: enum + columna + cast, nada de
// no_external_load/unit/validación semántica (fases D2/D3), nada de
// snapshot (D4), nada de Filament (D5). Ver docs de Hito D.

it('LoadModality enum has exactly None and Required, with the expected string values', function () {
    expect(LoadModality::cases())->toHaveCount(2);
    expect(LoadModality::None->value)->toBe('none');
    expect(LoadModality::Required->value)->toBe('required');
});

it('a newly created Exercise has load_modality=NULL by default — never inferred, never defaulted to Required', function () {
    $exercise = Exercise::factory()->create();

    expect($exercise->load_modality)->toBeNull();
    expect($exercise->fresh()->load_modality)->toBeNull();
});

it('load_modality persists and casts to LoadModality::None when explicitly set', function () {
    $exercise = Exercise::factory()->create(['load_modality' => LoadModality::None]);

    expect($exercise->fresh()->load_modality)->toBe(LoadModality::None);
});

it('load_modality persists and casts to LoadModality::Required when explicitly set', function () {
    $exercise = Exercise::factory()->create(['load_modality' => LoadModality::Required]);

    expect($exercise->fresh()->load_modality)->toBe(LoadModality::Required);
});

it('the enum cast never converts NULL into a real case — NULL stays NULL, it is not "None" nor "Required"', function () {
    $exercise = Exercise::factory()->create(['load_modality' => null]);
    $fresh = $exercise->fresh();

    expect($fresh->load_modality)->toBeNull();
    expect($fresh->load_modality)->not->toBe(LoadModality::None);
    expect($fresh->load_modality)->not->toBe(LoadModality::Required);
});

it('load_modality can be updated independently without touching any other Exercise field (backward compatibility)', function () {
    $exercise = Exercise::factory()->create([
        'muscle_group' => 'legs',
        'tracking_type' => TrackingType::RepsAndLoad,
        'equipment_needed' => ['dumbbells'],
    ]);

    $exercise->update(['load_modality' => LoadModality::Required]);
    $fresh = $exercise->fresh();

    expect($fresh->load_modality)->toBe(LoadModality::Required);
    // Nada más cambió — la columna es 100% aditiva.
    expect($fresh->muscle_group)->toBe('legs');
    expect($fresh->tracking_type)->toBe(TrackingType::RepsAndLoad);
    expect($fresh->equipment_needed)->toBe(['dumbbells']);
});

// ── Hito D (diseño formal v2 aprobado, fase D4): snapshot de tracking_type/
// load_modality ───────────────────────────────────────────────────────────
// Alcance ESTRICTO: solo Exercise::toSnapshot(). Ningún fallback de
// compatibilidad de D3 (null→Required) aplica aquí — el snapshot refleja
// EXACTAMENTE la clasificación del Exercise en vivo, incluido null.

it('1. RepsAndLoad + None: el snapshot congela ambos valores', function () {
    $exercise = Exercise::factory()->create(['tracking_type' => TrackingType::RepsAndLoad, 'load_modality' => LoadModality::None]);

    $snapshot = $exercise->toSnapshot();

    expect($snapshot['tracking_type'])->toBe('reps_and_load');
    expect($snapshot['load_modality'])->toBe('none');
});

it('2. RepsAndLoad + Required: el snapshot congela ambos valores', function () {
    $exercise = Exercise::factory()->create(['tracking_type' => TrackingType::RepsAndLoad, 'load_modality' => LoadModality::Required]);

    $snapshot = $exercise->toSnapshot();

    expect($snapshot['tracking_type'])->toBe('reps_and_load');
    expect($snapshot['load_modality'])->toBe('required');
});

it('3. TimeBased + Required: el snapshot congela ambos valores', function () {
    $exercise = Exercise::factory()->create(['tracking_type' => TrackingType::TimeBased, 'load_modality' => LoadModality::Required]);

    $snapshot = $exercise->toSnapshot();

    expect($snapshot['tracking_type'])->toBe('time_based');
    expect($snapshot['load_modality'])->toBe('required');
});

it('4. load_modality=null: el snapshot congela null — nunca se infiere Required (esa compatibilidad es exclusiva de D3, nunca de este método)', function () {
    $exercise = Exercise::factory()->create(['tracking_type' => TrackingType::RepsAndLoad, 'load_modality' => null]);

    $snapshot = $exercise->toSnapshot();

    expect($snapshot['load_modality'])->toBeNull();
    expect(array_key_exists('load_modality', $snapshot))->toBeTrue(); // la clave existe, con valor null — no está simplemente ausente
});

it('5. los campos ya existentes del snapshot permanecen exactamente intactos junto a los dos nuevos', function () {
    $exercise = Exercise::factory()->create([
        'name' => 'Curl con mancuernas',
        'instructions' => ['Paso 1', 'Paso 2'],
        'important_points' => ['Codo fijo'],
        'common_mistakes' => ['Balancear el cuerpo'],
        'breathing_cue' => 'Exhala al subir',
        'video_url' => 'https://videos.example.test/curl.mp4',
        'muscle_group' => 'arms',
        'secondary_muscles' => ['forearms'],
        'tracking_type' => TrackingType::RepsAndLoad,
        'load_modality' => LoadModality::Required,
    ]);

    $snapshot = $exercise->toSnapshot();

    expect($snapshot['name'])->toBe('Curl con mancuernas');
    expect($snapshot['instructions'])->toBe(['Paso 1', 'Paso 2']);
    expect($snapshot['important_points'])->toBe(['Codo fijo']);
    expect($snapshot['common_mistakes'])->toBe(['Balancear el cuerpo']);
    expect($snapshot['breathing_cue'])->toBe('Exhala al subir');
    expect($snapshot['video_url'])->toBe('https://videos.example.test/curl.mp4');
    expect($snapshot['muscle_group'])->toBe('arms');
    expect($snapshot['secondary_muscles'])->toBe(['forearms']);
    // Y los dos nuevos, en el mismo snapshot.
    expect($snapshot['tracking_type'])->toBe('reps_and_load');
    expect($snapshot['load_modality'])->toBe('required');
});

it('6. cambiar Exercise::load_modality DESPUÉS de generar y persistir un snapshot no modifica ese snapshot ya guardado', function () {
    $exercise = Exercise::factory()->create(['tracking_type' => TrackingType::RepsAndLoad, 'load_modality' => LoadModality::None]);
    $session = WorkoutSession::factory()->create();
    $workoutExercise = WorkoutExercise::create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
        'phase' => WorkoutExercisePhase::Main,
        'prescribed_sets' => 3,
        'prescribed_reps' => 10,
        'exercise_snapshot' => $exercise->toSnapshot(),
    ]);

    expect($workoutExercise->fresh()->exercise_snapshot['load_modality'])->toBe('none');

    $exercise->update(['load_modality' => LoadModality::Required]);

    // El snapshot ya persistido permanece exactamente igual...
    expect($workoutExercise->fresh()->exercise_snapshot['load_modality'])->toBe('none');
    // ...aunque el Exercise en vivo sí cambió (y un snapshot NUEVO lo reflejaría).
    expect($exercise->fresh()->load_modality)->toBe(LoadModality::Required);
    expect($exercise->fresh()->toSnapshot()['load_modality'])->toBe('required');
});

it('7. un snapshot histórico sin las claves nuevas (creado antes de este hito) sigue siendo tolerado — ningún consumidor actual lee tracking_type/load_modality desde el snapshot', function () {
    // Forma EXACTA de toSnapshot() antes de D4 — simula un WorkoutExercise
    // creado antes de este hito, cuyo exercise_snapshot ya persistido nunca
    // se reescribe retroactivamente (inmutabilidad histórica).
    $legacySnapshot = [
        'name' => 'Plancha', 'instructions' => ['Mantén la espalda recta'],
        'important_points' => null, 'common_mistakes' => null, 'breathing_cue' => null,
        'video_url' => 'https://videos.example.test/plank.mp4', 'muscle_group' => 'core',
        'primary_muscle' => null, 'secondary_muscles' => null,
        'provider' => null, 'provider_exercise_id' => null,
    ];
    $exercise = Exercise::factory()->create();
    $session = WorkoutSession::factory()->create();
    $workoutExercise = WorkoutExercise::create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
        'phase' => WorkoutExercisePhase::Main,
        'prescribed_sets' => 1,
        'exercise_snapshot' => $legacySnapshot,
    ]);

    $fresh = $workoutExercise->fresh();

    // Ningún error al leer el snapshot sin las claves nuevas.
    expect($fresh->exercise_snapshot['name'])->toBe('Plancha');
    expect($fresh->exercise_snapshot)->not->toHaveKey('tracking_type');
    expect($fresh->exercise_snapshot)->not->toHaveKey('load_modality');
    // Lectura defensiva (el patrón que cualquier consumidor futuro debería
    // usar) resuelve a null sin excepción — ninguna, hoy, hace esto de otra
    // forma: CoachContextProvider/TrainingEngine leen tracking_type de la
    // relación `exercise` EN VIVO, nunca de exercise_snapshot.
    expect($fresh->exercise_snapshot['tracking_type'] ?? null)->toBeNull();
    expect($fresh->exercise_snapshot['load_modality'] ?? null)->toBeNull();
});
