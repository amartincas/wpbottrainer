<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\LoadModality;
use App\Training\Enums\TrackingType;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\ExecutionReportRecorder;
use App\Training\Support\ExecutionReportService;
use Illuminate\Support\Facades\Http;

/**
 * Hito D (diseño formal v2 aprobado, fase D3) — integración
 * ExecutionReportRecorder + ExerciseSetValidator. Demuestra que la
 * validación semántica se ejecuta DESPUÉS de resolver el WorkoutExercise
 * real (nunca antes: aquí SIEMPRE se llega vía record()->resolveExercise(),
 * el mismo flujo real de producción), que un set semánticamente inválido
 * nunca genera ExerciseSet, y que uno válido se persiste ya sanitizado.
 * Prefijo "esv" en los helpers, mismo criterio del resto de la suite.
 */
function esvReadyContact(): Contact
{
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

/**
 * @return array{0: WorkoutSession, 1: WorkoutExercise}
 */
function esvSessionWithExercise(Contact $contact, TrackingType $trackingType, ?LoadModality $loadModality, string $name = 'Sentadilla'): array
{
    $exercise = Exercise::factory()->create([
        'name' => $name,
        'tracking_type' => $trackingType,
        'load_modality' => $loadModality,
    ]);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $workoutExercise = WorkoutExercise::create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
        'phase' => WorkoutExercisePhase::Main,
        'prescribed_sets' => 3,
        'prescribed_reps' => $trackingType === TrackingType::RepsAndLoad ? 10 : null,
        'prescribed_load' => null,
        'prescribed_duration_seconds' => $trackingType === TrackingType::TimeBased ? 90 : null,
        'rest_seconds' => 60,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'delivered_at' => now(),
    ]);

    return [$session, $workoutExercise];
}

// exercise_name null a propósito: resolveExercise() cae al fallback de
// frontExerciseId (ya pasado por cada test a record()), evitando acoplar
// este helper al nombre concreto de cada Exercise de prueba.
function esvReport(array $sets): array
{
    return [
        'reports' => [[
            'exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
            'sets' => $sets, 'rpe' => null, 'note' => null, 'uncertain' => false,
        ]],
    ];
}

it('D3: the validator runs AFTER resolving the real Exercise — a bodyweight (None) exercise never persists a load hallucinated by the LLM', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::RepsAndLoad, LoadModality::None);

    (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => 10, 'load' => 20.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false],
    ]), $we->id);

    $log = ExerciseLog::where('workout_exercise_id', $we->id)->first();
    expect($log)->not->toBeNull();
    $set = $log->exerciseSets->first();
    expect($set)->not->toBeNull();
    expect($set->actual_reps)->toBe(10);
    expect($set->actual_load)->toBeNull(); // nunca 20.0, nunca 0 — None es incondicional
});

it('D3: a weighted (Required) exercise persists an explicit load unchanged', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::RepsAndLoad, LoadModality::Required);

    (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => 10, 'load' => 40.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false],
    ]), $we->id);

    $set = ExerciseLog::where('workout_exercise_id', $we->id)->first()->exerciseSets->first();
    expect((float) $set->actual_load)->toBe(40.0);
});

it('D3: a weighted (Required) exercise persists 0 when the user explicitly declared no external load', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::RepsAndLoad, LoadModality::Required);

    (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => 10, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => true],
    ]), $we->id);

    $set = ExerciseLog::where('workout_exercise_id', $we->id)->first()->exerciseSets->first();
    expect((float) $set->actual_load)->toBe(0.0);
    expect($set->actual_reps)->toBe(10);
});

it('D3: a semantically invalid set (only a hallucinated load on a bodyweight exercise, no other data) never generates an ExerciseSet at all', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::RepsAndLoad, LoadModality::None);

    (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => null, 'load' => 20.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false],
    ]), $we->id);

    $log = ExerciseLog::where('workout_exercise_id', $we->id)->first();
    expect($log)->not->toBeNull(); // el ExerciseLog sí se crea (cabecera del reporte)
    expect($log->exerciseSets)->toHaveCount(0); // pero ningún ExerciseSet — el set quedó vacío tras la sanitización
});

it('D3: an unclassified exercise (load_modality=null, never curated) behaves as Required — accepts the reported load', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::RepsAndLoad, null);

    (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => 10, 'load' => 40.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false],
    ]), $we->id);

    $set = ExerciseLog::where('workout_exercise_id', $we->id)->first()->exerciseSets->first();
    expect((float) $set->actual_load)->toBe(40.0);
    // El fallback nunca se escribe de vuelta al catálogo.
    expect($we->exercise->fresh()->load_modality)->toBeNull();
});

it('D3: a TimeBased+Required exercise (e.g. Weighted Vest Plank) persists BOTH duration and load together', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::TimeBased, LoadModality::Required, 'Weighted Vest Plank');

    (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => null, 'load' => 5.0, 'unit' => 'kg', 'duration_seconds' => 60, 'no_external_load' => false],
    ]), $we->id);

    $set = ExerciseLog::where('workout_exercise_id', $we->id)->first()->exerciseSets->first();
    expect($set->actual_duration_seconds)->toBe(60);
    expect((float) $set->actual_load)->toBe(5.0);
    expect($set->actual_reps)->toBeNull();
});

it('D3: a TimeBased exercise never persists reps, even if D2 delivered one (tracking_type mismatch)', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::TimeBased, LoadModality::None, 'Plancha');

    (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => 10, 'load' => null, 'unit' => null, 'duration_seconds' => 45, 'no_external_load' => false],
    ]), $we->id);

    $set = ExerciseLog::where('workout_exercise_id', $we->id)->first()->exerciseSets->first();
    expect($set->actual_reps)->toBeNull();
    expect($set->actual_duration_seconds)->toBe(45);
});

it('D3: multiple sets in the same report are sanitized independently, discarding only the invalid ones', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::RepsAndLoad, LoadModality::None);

    (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => 10, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => false],
        ['reps' => null, 'load' => 20.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false], // vacío tras sanitizar
        ['reps' => 8, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => false],
    ]), $we->id);

    $sets = ExerciseLog::where('workout_exercise_id', $we->id)->first()->exerciseSets;
    expect($sets)->toHaveCount(2);
    expect($sets->pluck('actual_reps')->all())->toBe([10, 8]);
});

// ════════════════════════════════════════════════════════════════════
// Ajuste de coherencia post-validación — summaryOf()/isPartialReport()
// deben describir/contar EXACTAMENTE lo persistido (ya sanitizado por
// ExerciseSetValidator), nunca $report['sets'] crudo.
// ════════════════════════════════════════════════════════════════════

it('A. RepsAndLoad + None — "10 reps con 20 kg": persiste load=null y el resumen NUNCA afirma la carga descartada', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::RepsAndLoad, LoadModality::None);

    $outcome = (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => 10, 'load' => 20.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false],
    ]), $we->id);

    $set = ExerciseLog::where('workout_exercise_id', $we->id)->first()->exerciseSets->first();
    expect($set->actual_reps)->toBe(10);
    expect($set->actual_load)->toBeNull();

    expect($outcome->logged)->toHaveCount(1);
    expect($outcome->logged[0])->not->toContain('20');
    expect($outcome->logged[0])->not->toContain('kg');
});

it('B. RepsAndLoad + Required — "10 reps con 20 kg": persiste load=20 y el resumen SÍ puede mencionarla', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::RepsAndLoad, LoadModality::Required);

    $outcome = (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => 10, 'load' => 20.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false],
    ]), $we->id);

    $set = ExerciseLog::where('workout_exercise_id', $we->id)->first()->exerciseSets->first();
    expect((float) $set->actual_load)->toBe(20.0);

    expect($outcome->logged[0])->toContain('20');
    expect($outcome->logged[0])->toContain('kg');
});

it('C. TimeBased + None con carga enviada: la carga se descarta y nunca aparece en el resumen', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::TimeBased, LoadModality::None, 'Plancha');

    $outcome = (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => null, 'load' => 15.0, 'unit' => 'kg', 'duration_seconds' => 45, 'no_external_load' => false],
    ]), $we->id);

    $set = ExerciseLog::where('workout_exercise_id', $we->id)->first()->exerciseSets->first();
    expect($set->actual_load)->toBeNull();
    expect($set->actual_duration_seconds)->toBe(45);

    expect($outcome->logged[0])->not->toContain('15');
    expect($outcome->logged[0])->not->toContain('kg');
});

it('D. un set vacío tras la sanitización nunca se cuenta como reporte parcial ni se describe como registrado', function () {
    $contact = esvReadyContact();
    [$session, $we] = esvSessionWithExercise($contact, TrackingType::RepsAndLoad, LoadModality::None);

    $outcome = (new ExecutionReportRecorder)->record($session, esvReport([
        ['reps' => null, 'load' => 20.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false],
    ]), $we->id);

    $log = ExerciseLog::where('workout_exercise_id', $we->id)->first();
    expect($log)->not->toBeNull(); // la cabecera SÍ se crea
    expect($log->exerciseSets)->toHaveCount(0); // pero ningún ExerciseSet

    // Nunca se marca como "reporte parcial" — eso implicaría que sí se
    // registró algo, solo que menos series de las prescritas.
    expect($outcome->partialExerciseIds)->not->toContain($we->id);
    // El resumen genérico ("tu reporte de X") nunca afirma una cifra
    // concreta que en realidad fue descartada.
    expect($outcome->logged[0])->not->toContain('20');
});

// ── Hito D6 (cierre de huecos E2E) — conversión de unidad y descarte de
// unidad desconocida verificados a través del PIPELINE COMPLETO (D2+D3),
// no solo de forma aislada en cada fase ──────────────────────────────────

// Hallazgo real durante la escritura de estos dos tests: pasar
// `'load' => 45.0, 'unit' => 'lb'` DIRECTO a record() (como el resto de
// este archivo) NO representa el pipeline real — esa conversión es
// exclusivamente responsabilidad de D2 (ExecutionReportService), que
// ExerciseSetValidator (D3) NUNCA repite por diseño. Para probar
// genuinamente el pipeline COMPLETO (D2+D3), estos dos tests encadenan la
// extracción real (con la IA mockeada) antes de persistir — a diferencia
// de los demás tests de este archivo, que legítimamente empiezan
// directo en D3 con datos ya normalizados a mano.
it('E. lb→kg conversion survives the full pipeline (D2 extraction + D3 persistence) into ExerciseSet', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    [$session, $we] = esvSessionWithExercise($contact->fresh(), TrackingType::RepsAndLoad, LoadModality::Required, 'Curl con mancuernas');

    Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'reports' => [[
            'exercise_name' => null, 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 45, 'unit' => 'lb', 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ])]]]], 200)]);

    $extraction = (new ExecutionReportService)->extractReport('10 con 45 lb', [['name' => 'Curl con mancuernas']], $tenant, frontExerciseName: 'Curl con mancuernas');
    (new ExecutionReportRecorder)->record($session, $extraction, $we->id);

    $set = ExerciseLog::where('workout_exercise_id', $we->id)->first()->exerciseSets->first();
    expect((float) $set->actual_load)->toBe(20.41); // 45 lb * 0.453592, HALF_UP a 2 decimales
});

it('F. an unrecognized unit is discarded through the full pipeline (D2 extraction + D3 persistence), never interpreted as kg', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    [$session, $we] = esvSessionWithExercise($contact->fresh(), TrackingType::RepsAndLoad, LoadModality::Required, 'Curl con mancuernas');

    Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'reports' => [[
            'exercise_name' => null, 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 20, 'unit' => 'oz', 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ])]]]], 200)]);

    $extraction = (new ExecutionReportService)->extractReport('10 con 20 onzas', [['name' => 'Curl con mancuernas']], $tenant, frontExerciseName: 'Curl con mancuernas');
    (new ExecutionReportRecorder)->record($session, $extraction, $we->id);

    $set = ExerciseLog::where('workout_exercise_id', $we->id)->first()->exerciseSets->first();
    expect($set->actual_load)->toBeNull(); // nunca 20.0 — "oz" nunca es kg, ni en D2 ni en D3
    expect($set->actual_reps)->toBe(10);
});
