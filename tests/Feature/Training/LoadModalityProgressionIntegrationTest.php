<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\LoadModality;
use App\Training\Enums\ProgressionIntensitySignal;
use App\Training\Enums\TrackingType;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\ExecutionReportRecorder;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingHistoryContextProvider;
use App\Training\Support\TrainingPreferenceResolver;

/**
 * Hito D (D6 — cierre del hito) — el eslabón final, nunca antes probado de
 * punta a punta: WhatsApp → ExecutionReportService (D2) →
 * ExecutionReportRecorder/ExerciseSetValidator (D3) → ExerciseSet real →
 * TrainingHistoryContextProvider → ProgressionEvaluator. Todos los tests
 * previos de D1-D5 verificaban las piezas por separado (ExerciseSetValidator
 * de forma pura, o el Recorder hasta ExerciseSet) — ninguno verificaba que
 * el resultado sobreviviera intacto hasta la capa de progresión real. Este
 * archivo cierra exactamente ese hueco, usando ExecutionReportRecorder real
 * (nunca los helpers `pExecution()` de ProgressionEvaluatorTest.php, que
 * crean ExerciseSet directo por factory, sin pasar por D2/D3).
 */
function lmpiHistoryProvider(): TrainingHistoryContextProvider
{
    return new TrainingHistoryContextProvider(new SafetyRestrictionResolver(new BodyRegionCanonicalMapper), new TrainingPreferenceResolver);
}

function lmpiReadyContact(): Contact
{
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

/**
 * @return array{0: WorkoutSession, 1: WorkoutExercise}
 */
function lmpiSessionWithExercise(Contact $contact, LoadModality $loadModality): array
{
    $exercise = Exercise::factory()->create(['tracking_type' => TrackingType::RepsAndLoad, 'load_modality' => $loadModality]);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled, 'scheduled_at' => now()]);
    $we = WorkoutExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'order' => 1,
        'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 1, 'prescribed_reps' => 10,
        'exercise_snapshot' => $exercise->toSnapshot(), 'delivered_at' => now(),
    ]);

    return [$session, $we];
}

it('a load discarded by LoadModality::None never surfaces as historical intensity for ProgressionEvaluator (full real pipeline, no shortcuts)', function () {
    $contact = lmpiReadyContact();
    [$session, $we] = lmpiSessionWithExercise($contact, LoadModality::None);
    $exercise = $we->exercise;

    // Reporte REAL, exactamente la forma que entrega ExecutionReportService
    // (D2) ya normalizado — el LLM "alucina" una carga sobre un ejercicio
    // bodyweight, ej. "10 reps con 20 kg" mal atribuido.
    (new ExecutionReportRecorder)->record($session, [
        'reports' => [[
            'exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
            'sets' => [['reps' => 10, 'load' => 20.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false]],
            'rpe' => null, 'note' => null, 'uncertain' => false,
        ]],
    ], $we->id);

    // Único Main de la sesión, ya reportado -> se cierra sola.
    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Completed);

    $set = $we->fresh()->exerciseLog->exerciseSets->first();
    expect($set->actual_load)->toBeNull(); // ya verificado en D3, reconfirmado aquí como precondición

    $context = lmpiHistoryProvider()->build($contact->fresh());
    $evaluation = (new ProgressionEvaluator)->evaluate($context, $exercise->id, TrackingType::RepsAndLoad);

    // La prueba real: la carga alucinada nunca llega a ser un dato de
    // intensidad — ni "0", ni "20", ni ningún otro valor. NotApplicable es
    // el mismo estado que un bodyweight genuino sin ningún dato de carga.
    expect($evaluation->metrics->lastIntensity)->toBeNull();
    expect($evaluation->metrics->intensitySignal)->toBe(ProgressionIntensitySignal::NotApplicable);
});

it('contraste: una carga correctamente persistida en un ejercicio Required SÍ aparece como intensidad histórica real', function () {
    $contact = lmpiReadyContact();
    [$session, $we] = lmpiSessionWithExercise($contact, LoadModality::Required);
    $exercise = $we->exercise;

    (new ExecutionReportRecorder)->record($session, [
        'reports' => [[
            'exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
            'sets' => [['reps' => 10, 'load' => 20.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false]],
            'rpe' => null, 'note' => null, 'uncertain' => false,
        ]],
    ], $we->id);

    $context = lmpiHistoryProvider()->build($contact->fresh());
    $evaluation = (new ProgressionEvaluator)->evaluate($context, $exercise->id, TrackingType::RepsAndLoad);

    // Mismo mensaje, mismo número — la ÚNICA diferencia es la clasificación
    // del ejercicio, y eso basta para que la carga sea (correctamente) una
    // señal real de intensidad aquí, y nula en el test anterior.
    expect($evaluation->metrics->lastIntensity)->toBe(20.0);
});

it('un no_external_load=true real (Required) SÍ contribuye como intensidad 0.0 — nunca contaminación, es una señal genuina', function () {
    $contact = lmpiReadyContact();
    [$session, $we] = lmpiSessionWithExercise($contact, LoadModality::Required);
    $exercise = $we->exercise;

    (new ExecutionReportRecorder)->record($session, [
        'reports' => [[
            'exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
            'sets' => [['reps' => 10, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => true]],
            'rpe' => null, 'note' => null, 'uncertain' => false,
        ]],
    ], $we->id);

    $context = lmpiHistoryProvider()->build($contact->fresh());
    $evaluation = (new ProgressionEvaluator)->evaluate($context, $exercise->id, TrackingType::RepsAndLoad);

    expect($evaluation->metrics->lastIntensity)->toBe(0.0);
});
