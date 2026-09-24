<?php

use App\ExerciseCatalog\Importer\ExerciseImporter;
use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\DurationEstimator;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingAccessGate;
use App\Training\Support\TrainingHistoryContextProvider;
use App\Training\Support\TrainingPreferenceResolver;
use Illuminate\Support\Facades\Http;

/**
 * Hito Provider-Agnostic Normalization — test de integración obligatorio
 * (Audit #4/revisión de diseño): prueba la cadena COMPLETA, real, de
 * principio a fin, en vez de probar cada capa por separado:
 *
 *   YMove raw (equipment desconocido)
 *       → YMoveExerciseNormalizer (vía ExerciseImporter::importOne())
 *       → NormalizedExerciseData
 *       → Exercise.equipment_needed === ['unsupported']
 *       → TrainingEngine::isEligible() → false, incluso con
 *         equipment_fully_equipped=true.
 *
 * Es el contrato que conecta normalización con elegibilidad — si algo en
 * cualquiera de las dos capas se desalinea, este test lo detecta sin que
 * haga falta razonar sobre las dos capas a la vez.
 */
it('propagates an unrecognized YMove equipment value all the way to a real ineligibility decision', function () {
    Http::fake([
        'exercise-api.ymove.app/*' => Http::response([
            'data' => [
                'id' => 'ymove-real-flow-1',
                'title' => 'Some Brand New Apparatus Move',
                'muscleGroup' => 'back',
                'equipment' => 'a device YMove added after our last sync',
                'instructions' => ['Do the thing.'],
            ],
        ], 200),
    ]);

    $exercise = (new ExerciseImporter(new ProviderRegistry))->importOne('ymove', 'ymove-real-flow-1');

    // Capa 1: normalización — nunca [] para un valor desconocido.
    expect($exercise->equipment_needed)->toBe(['unsupported']);
    expect($exercise->is_active)->toBeFalse(); // nunca activo automáticamente

    // Se activa manualmente aquí SOLO para poder probar isEligible() a
    // través de decideNextSession() (el punto de entrada público real) —
    // no se está afirmando que este ejercicio debería activarse en
    // producción; eso sigue siendo una decisión humana separada.
    $exercise->update(['is_active' => true, 'contraindications' => []]);

    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'split_type' => SplitType::FullBody,
        'goal' => TrainingGoal::GeneralFitness,
        'training_location' => TrainingLocation::Gym,
        'equipment_fully_equipped' => true, // "tengo de todo" — el escape que este hito cierra
        'available_equipment' => [],
    ]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    // Hito R1/R2/R3 — TrainingEngine ya no crea una WorkoutSession sin al
    // menos 1 ejercicio de bloque principal elegible; este test verifica
    // la propagación de "unsupported" hasta la ineligibilidad, no la
    // disponibilidad de catálogo, así que necesita un candidato real
    // además del excluido.
    Exercise::factory()->create(['muscle_group' => 'back', 'equipment_needed' => []]);

    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);
    $engine = new TrainingEngine(
        new TrainingAccessGate,
        $safetyResolver,
        new TrainingHistoryContextProvider($safetyResolver, new TrainingPreferenceResolver),
        new ProgressionEvaluator,
        new DurationEstimator,
        new TrainingPreferenceResolver,
    );

    $session = $engine->decideNextSession($contact->fresh());

    // Capa 2: elegibilidad — el ejercicio de equipo desconocido nunca
    // aparece seleccionado, ni siquiera bajo "tengo de todo".
    expect($session->workoutExercises->pluck('exercise_id')->all())->not->toContain($exercise->id);
});
