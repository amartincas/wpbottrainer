<?php

use App\Models\Exercise;
use App\Models\ExerciseVideoAccess;
use App\Models\User;
use App\Training\Enums\TrackingType;

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
