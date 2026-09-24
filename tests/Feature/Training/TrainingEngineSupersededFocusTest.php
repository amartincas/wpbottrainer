<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\DurationEstimator;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrainingHistoryContextProvider;
use App\Training\Support\TrainingPreferenceResolver;

/**
 * Hito B2 — casos 11/12 del diseño aprobado: `Superseded` SÍ participa en
 * `TrainingEngine::varietyScore()` (igual que `Skipped`/`Completed`), pero
 * NUNCA dispara el reintento de foco específico de `Skipped` en
 * `decideFocus()`. Prefijo "sf" (superseded-focus) en los helpers para
 * evitar colisión de funciones globales.
 */
function sfContact(SplitType $splitType = SplitType::FullBody, array $profileOverrides = []): Contact
{
    $contact = Contact::factory()->create();

    TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'split_type' => $splitType,
        'goal' => TrainingGoal::GeneralFitness,
        'experience_level' => ExperienceLevel::Beginner,
    ], $profileOverrides));

    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

function sfEngine(): TrainingEngine
{
    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);

    return new TrainingEngine(
        new TrainingAccessGate,
        $safetyResolver,
        new TrainingHistoryContextProvider($safetyResolver, new TrainingPreferenceResolver),
        new ProgressionEvaluator,
        new DurationEstimator,
        new TrainingPreferenceResolver,
    );
}

/**
 * WorkoutExercise Main manual, con `exercise_snapshot.muscle_group`
 * controlado — usado para construir `focusOf()` determinísticamente sin
 * depender de la selección real de TrainingEngine.
 */
function sfMainExercise(WorkoutSession $session, string $muscleGroup, int $order): void
{
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id,
        'phase' => WorkoutExercisePhase::Main,
        'order' => $order,
        'exercise_snapshot' => ['name' => "Ejercicio {$muscleGroup} {$order}", 'muscle_group' => $muscleGroup, 'primary_muscle' => null],
    ]);
}

// ── Caso 11: Superseded participa en variedad ───────────────────────────

it('11: a Superseded session counts as recent exposure for varietyScore, same as Skipped/Completed', function () {
    // 9 minutos -> exactamente 1 slot de Main (ver DurationEstimator: 3
    // sets x (120+60)s = 540s por ejercicio; mainBudgetMinutes ~6.3min ->
    // round(378/540)=1).
    $contact = sfContact();
    $contact->tenant()->update(['target_session_duration_minutes' => 9]);

    $exerciseA = Exercise::factory()->create([
        'muscle_group' => 'core', 'primary_muscle' => MuscleFocus::Chest,
        'difficulty_level' => 'beginner', 'equipment_needed' => [],
    ]);
    $exerciseB = Exercise::factory()->create([
        'muscle_group' => 'core', 'primary_muscle' => MuscleFocus::Chest,
        'difficulty_level' => 'beginner', 'equipment_needed' => [],
    ]);

    // Sesión Superseded reciente que YA mostró $exerciseA.
    $old = WorkoutSession::factory()->superseded()->create([
        'contact_id' => $contact->id,
        'scheduled_at' => now(),
    ]);
    WorkoutExercise::factory()->create([
        'workout_session_id' => $old->id,
        'exercise_id' => $exerciseA->id,
        'phase' => WorkoutExercisePhase::Main,
        'order' => 1,
        'exercise_snapshot' => $exerciseA->toSnapshot(),
    ]);

    $new = sfEngine()->decideNextSession($contact->fresh());
    $selectedMain = $new->workoutExercises->where('phase', WorkoutExercisePhase::Main)->first();

    // $exerciseA tiene varietyScore>0 (expuesto en la Superseded reciente);
    // $exerciseB tiene 0.0 (nunca visto) -> gana el desempate de variedad.
    expect($selectedMain->exercise_id)->toBe($exerciseB->id);
});

// ── Caso 12: Superseded NO dispara el reintento de Skipped ──────────────

it('12: a Skipped last session retries the same focus, but a Superseded last session does NOT — it follows the normal advance-or-keep rule', function (WorkoutSessionStatus $status, string $expectedFocus) {
    $contact = sfContact(SplitType::UpperLower, ['next_focus' => 'core,legs']);

    // Candidato "arms,back,chest,shoulders" entrenado hace 2 días -> nunca
    // debe ser detectado como descuidado por mostNeglectedFocus() (<=5
    // días), para que el test llegue realmente a la rama de decideFocus()
    // que distingue Skipped de Superseded, y no se resuelva antes por
    // "foco descuidado".
    $upperSession = WorkoutSession::factory()->completed()->create([
        'contact_id' => $contact->id,
        'scheduled_at' => now()->subDays(2),
    ]);
    sfMainExercise($upperSession, 'arms', 1);
    sfMainExercise($upperSession, 'back', 2);
    sfMainExercise($upperSession, 'chest', 3);
    sfMainExercise($upperSession, 'shoulders', 4);

    // Sesión bajo prueba (Skipped o Superseded, según el dataset): la MÁS
    // reciente (scheduled_at=now) -> es $lastSession en decideFocus().
    $lowerSession = WorkoutSession::factory()->create([
        'contact_id' => $contact->id,
        'status' => $status,
        'scheduled_at' => now(),
    ]);
    sfMainExercise($lowerSession, 'core', 1);
    sfMainExercise($lowerSession, 'legs', 2);

    // Catálogo elegible mínimo para que decideNextSession() no falle por
    // catálogo insuficiente, sin importar qué focus resulte.
    Exercise::factory()->create([
        'muscle_group' => 'core', 'primary_muscle' => MuscleFocus::Abs,
        'difficulty_level' => 'beginner', 'equipment_needed' => [],
    ]);
    Exercise::factory()->create([
        'muscle_group' => 'legs', 'primary_muscle' => MuscleFocus::Quads,
        'difficulty_level' => 'beginner', 'equipment_needed' => [],
    ]);
    Exercise::factory()->create([
        'muscle_group' => 'arms', 'primary_muscle' => MuscleFocus::Biceps,
        'difficulty_level' => 'beginner', 'equipment_needed' => [],
    ]);
    Exercise::factory()->create([
        'muscle_group' => 'back', 'primary_muscle' => MuscleFocus::Back,
        'difficulty_level' => 'beginner', 'equipment_needed' => [],
    ]);
    Exercise::factory()->create([
        'muscle_group' => 'chest', 'primary_muscle' => MuscleFocus::Chest,
        'difficulty_level' => 'beginner', 'equipment_needed' => [],
    ]);
    Exercise::factory()->create([
        'muscle_group' => 'shoulders', 'primary_muscle' => MuscleFocus::Shoulders,
        'difficulty_level' => 'beginner', 'equipment_needed' => [],
    ]);

    $session = sfEngine()->decideNextSession($contact->fresh());

    expect($session->prescription_context_snapshot['decided_focus'])->toBe($expectedFocus);
})->with([
    'Skipped retries the same focus (core,legs)' => [WorkoutSessionStatus::Skipped, 'core,legs'],
    'Superseded advances the rotation instead (arms,back,chest,shoulders)' => [WorkoutSessionStatus::Superseded, 'arms,back,chest,shoulders'],
]);
