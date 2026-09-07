<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WhatsAppMessage;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\TrackingType;
use App\Training\Enums\TrainingAccessStatus;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Facades\Http;

/**
 * Hito 6: cierra el ciclo prescripción → ejecución real → registro →
 * historial → próxima decisión del Training Engine. Cubre el reporte
 * conversacional de ejecución (ExerciseLog/ExerciseSet), la persistencia de
 * WhatsAppMessage (deuda de Hito 5), y la inmutabilidad de WorkoutExercise.
 */

function reportExtractionBody(array $payload): array
{
    return ['choices' => [['message' => ['content' => json_encode($payload)]]]];
}

function makeSessionWithExercises(Contact $contact, array $exerciseSpecs): array
{
    $session = WorkoutSession::factory()->create([
        'contact_id' => $contact->id,
        'status' => WorkoutSessionStatus::Scheduled,
    ]);

    $workoutExercises = [];

    foreach ($exerciseSpecs as $order => $spec) {
        $exercise = Exercise::factory()->create(array_merge([
            'name' => $spec['name'],
            'tracking_type' => $spec['tracking_type'] ?? TrackingType::RepsAndLoad,
        ], $spec['exercise_overrides'] ?? []));

        $workoutExercises[] = WorkoutExercise::factory()->create(array_merge([
            'workout_session_id' => $session->id,
            'exercise_id' => $exercise->id,
            'order' => $order + 1,
            'exercise_snapshot' => $exercise->toSnapshot(),
            'prescribed_sets' => 3,
            'prescribed_reps' => ($spec['tracking_type'] ?? null) === TrackingType::TimeBased ? null : 10,
            'prescribed_load' => null,
            'prescribed_duration_seconds' => ($spec['tracking_type'] ?? null) === TrackingType::TimeBased ? 30 : null,
        ], $spec['workout_exercise_overrides'] ?? []));
    }

    return [$session, $workoutExercises];
}

function readyTrainingContact(): Contact
{
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    // health_screening_asked: true — Bloque 5 (D048): sin esto, el perfil ya
    // no se consideraría "listo para entrenar" bajo el nuevo Registry.
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

function sendMessageAsContact(Contact $contact, ?string $body, string $messageType = 'text', ?string $mediaId = null): void
{
    $tenant = $contact->tenant;
    $job = new ProcessWhatsAppMessage($tenant, $contact->customer_phone, $body, 'wamid.'.uniqid(), $messageType, $mediaId);
    app()->call([$job, 'handle']);
}

// 1. Reporte completo (y verificación de que la sesión se cierra sola al
// quedar todo reportado — "integración con WorkoutSession").
it('records a full report with multiple sets and auto-completes the session once everything is reported', function () {
    $contact = readyTrainingContact();
    [$session, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla',
                'not_performed' => false,
                'sets' => [
                    ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                    ['reps' => 8, 'load' => 40, 'duration_seconds' => null],
                ],
                'rpe_number' => null,
                'rpe_category' => 'hard',
                'note' => 'Me costó bastante',
                'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Terminé las 3 series, hice 10, 10 y 8 con 40 kg. Me costó bastante.');

    $log = ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first();
    expect($log)->not->toBeNull();
    expect($log->rpe)->toBe(8);
    expect($log->note)->toBe('Me costó bastante');
    expect($log->exerciseSets)->toHaveCount(3);
    expect($log->exerciseSets->pluck('actual_reps')->all())->toBe([10, 10, 8]);

    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Completed);
    expect($session->fresh()->completed_at)->not->toBeNull();
});

// 2. Reporte parcial: 3 ejercicios, se reportan 2 (uno completo, otro
// explícitamente no realizado), el tercero queda intacto sin log.
it('records only what was actually reported in a partial session, leaving the rest untouched', function () {
    $contact = readyTrainingContact();
    [$session, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Press de banca'],
        ['name' => 'Remo'],
        ['name' => 'Curl de biceps'],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [
                [
                    'exercise_name' => 'Press de banca', 'not_performed' => false,
                    'sets' => [['reps' => 10, 'load' => 30, 'duration_seconds' => null]],
                    'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
                ],
                [
                    'exercise_name' => 'Remo', 'not_performed' => true,
                    'sets' => [], 'rpe_number' => null, 'rpe_category' => null,
                    'note' => 'sin tiempo', 'uncertain' => false,
                ],
            ],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Hice press de banca 10x30. El remo no lo hice, sin tiempo.');

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->exists())->toBeTrue();

    $skippedLog = ExerciseLog::where('workout_exercise_id', $workoutExercises[1]->id)->first();
    expect($skippedLog)->not->toBeNull();
    expect($skippedLog->note)->toContain('No realizado');
    expect($skippedLog->exerciseSets)->toHaveCount(0);

    // El tercer ejercicio nunca se mencionó — no debe existir ningún log.
    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[2]->id)->exists())->toBeFalse();

    // No todo fue reportado y no se dijo "terminé" -> la sesión sigue abierta.
    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Scheduled);
});

// 5. Ejercicio por tiempo.
it('records a time-based exercise using duration instead of reps/load', function () {
    $contact = readyTrainingContact();
    [$session, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Plancha', 'tracking_type' => TrackingType::TimeBased],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Plancha', 'not_performed' => false,
                'sets' => [['reps' => null, 'load' => null, 'duration_seconds' => 45]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => true,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Aguanté 45 segundos la plancha, eso fue todo');

    $log = ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first();
    expect($log->exerciseSets->first()->actual_duration_seconds)->toBe(45);
    expect($log->exerciseSets->first()->actual_reps)->toBeNull();
    // session_finished=true cierra la sesión aunque no todo esté reportado.
    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Completed);
});

// 6. RPE explícito por número, distinto del mapeo por categoría (ya cubierto
// en el test 1 con "hard").
it('accepts an explicit numeric RPE stated directly by the user', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => false,
                'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
                'rpe_number' => 9, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Sentadilla 10x40, le doy un 9 de esfuerzo');

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first()->rpe)->toBe(9);
});

// 7. Nota / observación.
it('persists a free-text observation alongside the sets', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => false,
                'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null,
                'note' => 'Sentí un tirón leve en la rodilla derecha', 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Sentadilla 10x40, sentí un tirón leve en la rodilla derecha');

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first()->note)
        ->toBe('Sentí un tirón leve en la rodilla derecha');
});

// 8. Datos faltantes: se menciona el ejercicio pero sin ningún número -> se pregunta.
it('asks for missing data instead of guessing when nothing quantifiable was reported', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => false,
                'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Hice la sentadilla');

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->exists())->toBeFalse();
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '¿Cuántas series'));
});

// 9. Datos ambiguos: lenguaje de duda -> se registra pero marcado, nunca
// silenciosamente como un hecho confirmado.
it('records hedged/uncertain quantities but marks them, never as a plain confirmed fact', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => false,
                'sets' => [['reps' => 10, 'load' => null, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => true,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Creo que hice unas 10 repeticiones');

    $log = ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first();
    expect($log)->not->toBeNull();
    expect($log->exerciseSets->first()->actual_reps)->toBe(10);
    expect($log->note)->toContain('incertidumbre');
});

// 10. Audio de entrada.
it('accepts an audio report, transcribes it, and processes it through the same extraction flow', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.test/audio.ogg'], 200),
        'cdn.example.test/*' => Http::response('fake-audio-bytes', 200),
        'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Sentadilla 10 repeticiones con 40 kilos'], 200),
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => false,
                'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, null, 'audio', 'media123');

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first())->not->toBeNull();
});

// 11. Duplicación: reportar el mismo ejercicio dos veces no crea un segundo log.
it('does not create a duplicate ExerciseLog when the same exercise is reported twice', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    $fakeExtraction = fn () => reportExtractionBody([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push($fakeExtraction())
            ->push($fakeExtraction()),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Sentadilla 10x40');
    sendMessageAsContact($contact, 'Sentadilla 10x40'); // reenvío/repetición

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->count())->toBe(1);
});

// 12. Ejercicio que no pertenece a la sesión.
it('never attaches a report to an exercise outside the active session', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Peso muerto', // no está en la sesión
                'not_performed' => false,
                'sets' => [['reps' => 10, 'load' => 60, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Peso muerto 10x60');

    expect(ExerciseLog::count())->toBe(0);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '¿a cuál ejercicio te refieres?'));
});

// 13 y 14. Persistencia de conversación (deuda de Hito 5, resuelta en Hito 6).
it('persists both the inbound user message and the outbound bot reply in WhatsAppMessage', function () {
    $contact = readyTrainingContact();
    makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => false,
                'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Sentadilla 10x40');

    $userMessage = WhatsAppMessage::where('tenant_id', $contact->tenant_id)
        ->where('customer_phone', $contact->customer_phone)
        ->where('role', 'user')
        ->first();
    expect($userMessage)->not->toBeNull();
    expect($userMessage->content)->toBe('Sentadilla 10x40');

    $botMessage = WhatsAppMessage::where('tenant_id', $contact->tenant_id)
        ->where('customer_phone', $contact->customer_phone)
        ->where('role', 'assistant')
        ->first();
    expect($botMessage)->not->toBeNull();
    expect($botMessage->content)->toContain('Registré');
});

// 18. Inmutabilidad: WorkoutExercise nunca se modifica al procesar un reporte.
it('never modifies the prescribed WorkoutExercise while recording what was executed', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);
    $before = $workoutExercises[0]->only(['prescribed_sets', 'prescribed_reps', 'prescribed_load', 'exercise_snapshot']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => false,
                'sets' => [['reps' => 55, 'load' => 999, 'duration_seconds' => null]], // muy distinto a lo prescrito
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Sentadilla 55x999');

    $after = $workoutExercises[0]->fresh()->only(['prescribed_sets', 'prescribed_reps', 'prescribed_load', 'exercise_snapshot']);
    expect($after)->toEqualCanonicalizing($before);
});

// 19. Usuario sin sesión activa: el "reporte" cae al flujo normal de generación.
it('falls back to generating a new session when there is no active session to report against', function () {
    $contact = readyTrainingContact();
    Exercise::factory()->create(['muscle_group' => 'chest']);

    // Bloque 9 (D052): sin sesión pendiente, CoachService es la única
    // llamada de IA de este camino — antes de este bloque no se hacía
    // ninguna. "continue_training" dispara determinísticamente la entrega
    // ya existente, sin importar el resto de la respuesta de la IA.
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'safety_signal_text' => null,
            'intents' => ['continue_training'],
            'training_reply' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Dame mi entrenamiento de hoy');

    expect(ExerciseLog::count())->toBe(0);
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
    // Una sola llamada de IA para todo el turno (D052/D026) — el resto de
    // peticiones son entrega de WhatsApp (deterministas, sin IA).
    $openAiCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.openai.com'));
    expect($openAiCalls)->toHaveCount(1);
});

// 20. Bloqueado por acceso.
it('blocks a report attempt when access is not granted, without touching ExerciseLog', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id, 'status' => TrainingAccessStatus::Expired, 'expires_at' => now()->subDay()]);
    [, $workoutExercises] = makeSessionWithExercises($contact->fresh(), [['name' => 'Sentadilla']]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    sendMessageAsContact($contact->fresh(), 'Sentadilla 10x40');

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->exists())->toBeFalse();
    // Mensaje actualizado en Hito 8.1 — instrucción explícita ("quiero pagar").
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'activar tu acceso'));
});

// 21. Bloqueado por seguridad.
it('blocks a report attempt when a safety signal is present, without touching ExerciseLog', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    sendMessageAsContact($contact, 'Hice la sentadilla pero ahora tengo un fuerte dolor de pecho');

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->exists())->toBeFalse();
    expect(TrainingProfile::where('contact_id', $contact->id)->first()->isFlaggedForSafetyReview())->toBeTrue();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
});

// ── Hito 8.3: fix real del hallazgo del E2E comercial — "hecho" ─────────

// 22. El defecto real: "hecho" (sin nombrar el ejercicio, sin métricas) NO
// debe reenviar el mismo ejercicio — con el prompt corregido, produce UN
// reporte que ExecutionReportRecorder ya sabía resolver (único ejercicio
// pendiente) y pedir la aclaración correspondiente.
it('never resends the same exercise/session when the user replies "hecho" without detail — asks for clarification instead', function () {
    $contact = readyTrainingContact();
    [$session, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Plancha']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            // Refleja exactamente la regla nueva del prompt: una confirmación
            // sin detalle sigue produciendo UN reporte, con exercise_name null.
            'reports' => [[
                'exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
                'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'hecho');

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->exists())->toBeFalse();
    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Scheduled);

    // Pide aclaración — nunca reenvía el ejercicio/video.
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '¿Cuántas series'));
    Http::assertNotSent(fn ($request) => data_get($request->data(), 'type') === 'video');
});

// 23. "no pude" vs "no quiero" — mismo not_performed, distinto skip_reason,
// nunca inventado si el usuario no da ninguna razón.
it('distinguishes "no pude" from "no quiero" via skip_reason, without inventing a reason when none is given', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => true, 'skip_reason' => 'dont_want',
                'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'no quiero hacer sentadillas hoy');

    $log = ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first();
    expect($log)->not->toBeNull();
    expect($log->skip_reason->value)->toBe('dont_want');
});

// ── Hardening pre-producción (hallazgo E2E de Bloque 9) ─────────────────

// 24. Formateo de carga en el texto de confirmación: enteros terminados en
// cero no deben perder ese cero (bug real reproducido en el E2E de staging:
// 40 se mostraba como "4kg"), y los decimales deben conservarse tal cual.
// El valor persistido (ExerciseSet.actual_load) nunca cambia — se verifica
// aparte, en el mismo test, contra los floats originales.
it('formats whole-number loads ending in zero correctly in the confirmation text, without altering the persisted value', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => false,
                'sets' => [
                    ['reps' => 10, 'load' => 10, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => 20, 'duration_seconds' => null],
                    ['reps' => 8, 'load' => 40, 'duration_seconds' => null],
                    ['reps' => 6, 'load' => 40.5, 'duration_seconds' => null],
                ],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Sentadilla: 10x10, 10x20, 8x40 y 6x40.5');

    Http::assertSent(fn ($request) => str_contains(
        data_get($request->data(), 'text.body', ''),
        '10rep@10kg, 10rep@20kg, 8rep@40kg, 6rep@40.5kg'
    ));

    $sets = ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first()->exerciseSets;
    expect($sets->pluck('actual_load')->map(fn ($load) => (float) $load)->all())->toBe([10.0, 20.0, 40.0, 40.5]);
});

it('records skip_reason as null when not_performed is true but no reason was given or implied', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => true, 'skip_reason' => null,
                'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'no la hice');

    $log = ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first();
    expect($log)->not->toBeNull();
    expect($log->skip_reason)->toBeNull();
});
