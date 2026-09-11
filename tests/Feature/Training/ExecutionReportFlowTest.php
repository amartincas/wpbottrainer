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
    // H16.1 (Cambio 4) — un TrainingAccess con status Expired ya no usa el
    // mensaje genérico de "activar tu acceso"/"quiero pagar": cae en la
    // rama "paid_expired" de resolveAccessDeniedMessage() (mismo trato que
    // Active/Free vencidos), redactado por TrialEndedMessageComposer — sin
    // IA fakeada aquí, degrada de forma determinista a su fallback fijo.
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'nada de tu progreso se perdió'));
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

    // H16.2 Fase 1.2 — lenguaje natural en vez de notación técnica: reps y
    // cargas totalmente mixtas se enumeran tal cual, sin resumir de más.
    Http::assertSent(fn ($request) => str_contains(
        data_get($request->data(), 'text.body', ''),
        '10, 10, 8 y 6 repeticiones con 10kg, 20kg, 40kg y 40.5kg'
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

// ── H16.2 Fase 1 — cierre de sesión: nunca "pendientes" + "completada" ────

it('"ya terminé" with real pendientes never completes the session and never claims completion', function () {
    $contact = readyTrainingContact();
    [$session, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Caminata de monstruo con banda'],
        ['name' => 'Sentadilla con banda'],
        ['name' => 'Fondos en banco'],
    ]);

    Http::fake([
        // Confirmación breve sin detalle: un elemento en "reports" con
        // exercise_name=null (mismo contrato ya validado en producción,
        // ver ExecutionReportService::buildPrompt()), + session_finished=true.
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => null, 'not_performed' => false,
                'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => true,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Ya terminé');

    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Scheduled);
    foreach ($workoutExercises as $we) {
        expect(ExerciseLog::where('workout_exercise_id', $we->id)->exists())->toBeFalse();
    }

    // Nunca afirma que la sesión terminó — el fake de IA responde JSON
    // (validate() lo rechaza), así que se usa el fallback determinista de
    // BlockedStillPending, que nombra los 3 pendientes reales.
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Caminata de monstruo con banda')
        && str_contains(data_get($request->data(), 'text.body', ''), 'Sentadilla con banda')
        && str_contains(data_get($request->data(), 'text.body', ''), 'Fondos en banco'));
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'completad')
        || str_contains(data_get($request->data(), 'text.body', ''), '🏁'));
});

it('"ya terminé" with everything reported in the same message closes the session with a SuccessFull message', function () {
    $contact = readyTrainingContact();
    [$session, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => false,
                'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => true,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Ya terminé, hice 10 con 40 en sentadilla');

    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Completed);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Entrenamiento completado'));
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'pendiente'));
});

it('two consecutive "ya terminé" attempts with pendientes never produce a "pendientes"+"completada" contradiction in either turn', function () {
    $contact = readyTrainingContact();
    [$session, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Sentadilla'],
        ['name' => 'Fondos en banco'],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(reportExtractionBody([
                'reports' => [['exercise_name' => null, 'not_performed' => false, 'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
                'session_finished' => true,
            ]))
            ->push(reportExtractionBody([
                'reports' => [['exercise_name' => null, 'not_performed' => false, 'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
                'session_finished' => true,
            ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Ya terminé');
    sendMessageAsContact($contact, 'Ya terminé');

    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Scheduled);

    // Ninguno de los 2 mensajes salientes mezcla "pendiente(s)" con
    // "completada"/"🏁" — la contradicción original nunca puede reaparecer.
    foreach (Http::recorded() as [$request, $response]) {
        if (! str_contains($request->url(), 'graph.facebook.com')) {
            continue;
        }

        $body = data_get($request->data(), 'text.body', '');
        $mentionsPending = str_contains($body, 'faltan') || str_contains($body, 'pendiente');
        $claimsCompleted = str_contains($body, 'completad') || str_contains($body, '🏁');

        expect($mentionsPending && $claimsCompleted)->toBeFalse();
    }
});

it('the session-close flow produces the same BlockedStillPending fallback for a Trial contact as for any other', function () {
    $contact = readyTrainingContact();
    TrainingAccess::where('contact_id', $contact->id)->update(['status' => TrainingAccessStatus::Trial]);
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla'], ['name' => 'Fondos en banco']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [['exercise_name' => null, 'not_performed' => false, 'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
            'session_finished' => true,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Ya terminé');

    // Mismo fallback determinista que produce cualquier otro estado de
    // acceso (Active/Free) — ver el siguiente test — nunca una rama por
    // TrainingAccessStatus dentro de determineSessionCloseIntent()/
    // SessionCloseMessageComposer.
    Http::assertSent(fn ($request) => data_get($request->data(), 'text.body') ===
        '¡Casi! 💪 Todavía te faltan estos ejercicios: Sentadilla, Fondos en banco. Cuando los tengas, cuéntame y cerramos el entrenamiento.');
});

it('the session-close flow produces the same BlockedStillPending fallback for an active paid membership contact', function () {
    $contact = readyTrainingContact();
    // readyTrainingContact() ya deja TrainingAccessStatus::Active por
    // defecto (ver factory) — sin overrides, a propósito, para que este
    // test y el anterior sean textualmente comparables.
    [, $workoutExercises] = makeSessionWithExercises($contact, [['name' => 'Sentadilla'], ['name' => 'Fondos en banco']]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [['exercise_name' => null, 'not_performed' => false, 'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
            'session_finished' => true,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Ya terminé');

    Http::assertSent(fn ($request) => data_get($request->data(), 'text.body') ===
        '¡Casi! 💪 Todavía te faltan estos ejercicios: Sentadilla, Fondos en banco. Cuando los tengas, cuéntame y cerramos el entrenamiento.');
});

// ── H16.2 Fase 1.1 — resolución contextual: el ejercicio recién mostrado
// se asume por defecto, sin preguntar "¿a cuál te refieres?" ──────────────

it('Test 1 — a report without an explicit exercise name is attributed to the currently presented exercise, never asking which one', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Elevaciones de gemelos con mancuernas'],
        ['name' => 'Curl de bíceps con mancuernas de pie'],
        ['name' => 'Hip Thrust con mancuerna'],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => null, 'not_performed' => false,
                'sets' => [
                    ['reps' => 10, 'load' => 8, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => 8, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => 8, 'duration_seconds' => null],
                ],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, '3 series de 10 con 8kg');

    // Se atribuye al PRIMERO (order más bajo) — el único que la entrega
    // progresiva ha mostrado hasta ahora — nunca a los otros 2 pendientes.
    $log = ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first();
    expect($log)->not->toBeNull();
    expect($log->exerciseSets)->toHaveCount(3);
    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[1]->id)->exists())->toBeFalse();
    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[2]->id)->exists())->toBeFalse();

    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'A cuál ejercicio te refieres'));
});

it('Test 2 — "listo" alone, right after the exercise was presented, asks only for the missing data, never the exercise name', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Curl de bíceps con mancuernas de pie'],
        ['name' => 'Hip Thrust con mancuerna'],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => null, 'not_performed' => false,
                'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Listo');

    foreach ($workoutExercises as $we) {
        expect(ExerciseLog::where('workout_exercise_id', $we->id)->exists())->toBeFalse();
    }

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '¿Cuántas series/repeticiones')
        && str_contains(data_get($request->data(), 'text.body', ''), 'Curl de bíceps con mancuernas de pie'));
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'A cuál ejercicio te refieres'));
});

it('Test 3 — after registering the current exercise, delivers exactly the next pending one, never re-sending the one just completed', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Elevaciones de gemelos con mancuernas'],
        ['name' => 'Curl de bíceps con mancuernas de pie'],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => null, 'not_performed' => false,
                'sets' => [['reps' => 10, 'load' => 8, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, '10 con 8kg');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '2. *Curl de bíceps con mancuernas de pie*'));
    // La tarjeta de entrega del ejercicio ya completado nunca se reenvía —
    // distinto de mencionarlo dentro del resumen "✅ Registré: ...".
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '1. *Elevaciones de gemelos con mancuernas*'));
});

it('Test 4 — a pure question about the current exercise is answered without registering a fictitious report or advancing', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Elevaciones de gemelos con mancuernas'],
        ['name' => 'Curl de bíceps con mancuernas de pie'],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [],
            'session_finished' => false,
            'intents' => ['exercise_question'],
            'training_reply' => 'Puedes usar el peso con el que sientas que las últimas repeticiones cuestan de verdad.',
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, '¿Qué peso debería usar?');

    foreach ($workoutExercises as $we) {
        expect(ExerciseLog::where('workout_exercise_id', $we->id)->exists())->toBeFalse();
    }
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'últimas repeticiones cuestan de verdad'));
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '2. *Curl de bíceps con mancuernas de pie*'));
});

it('Test 5 — an explicit reference to a different pending exercise resolves to that one, not the currently presented one', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Curl de bíceps con mancuernas de pie'],
        ['name' => 'Hip Thrust con mancuerna'],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => 'Hip Thrust con mancuerna', 'not_performed' => false,
                'sets' => [
                    ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                ],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'También hice hip thrust 3x10');

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->exists())->toBeFalse();
    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[1]->id)->exists())->toBeTrue();
});

// ── H16.2 Fase 1.1 — reproducción exacta de la conversación real que
// motivó el fix (mismos nombres de ejercicio, mismo flujo completo) ───────

it('reproduces the real pilot conversation: full report without a name registers to the shown exercise, then delivers exactly the next one', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Elevaciones de gemelos con mancuernas'],
        ['name' => 'Curl de bíceps con mancuernas de pie'],
        ['name' => 'Hip Thrust con mancuerna'],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => null, 'not_performed' => false,
                'sets' => [
                    ['reps' => 10, 'load' => 8, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => 8, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => 8, 'duration_seconds' => null],
                ],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, '3 series, 10 repeticiones por serie, con 8kg');

    // Se registró como "Elevaciones de gemelos con mancuernas" — el que se
    // acababa de mostrar — sin preguntar nunca a cuál se refería.
    $gemelosLog = ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first();
    expect($gemelosLog)->not->toBeNull();
    expect($gemelosLog->exerciseSets->pluck('actual_reps')->all())->toBe([10, 10, 10]);
    expect($gemelosLog->exerciseSets->pluck('actual_load')->map(fn ($l) => (float) $l)->all())->toBe([8.0, 8.0, 8.0]);
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'A cuál ejercicio te refieres'));

    // Se entrega EXACTAMENTE el siguiente (Curl) — nunca se reenvía la
    // tarjeta de "Elevaciones de gemelos con mancuernas".
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '2. *Curl de bíceps con mancuernas de pie*'));
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '1. *Elevaciones de gemelos con mancuernas*'));
    expect(ExerciseLog::where('workout_exercise_id', $workoutExercises[2]->id)->exists())->toBeFalse();
});

it('"Listo, 3 series de 10" — a uniform-sets confirmation without a name — registers directly, no clarification of any kind', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Elevaciones de gemelos con mancuernas'],
        ['name' => 'Curl de bíceps con mancuernas de pie'],
    ]);

    Http::fake([
        // "3 series de 10 sin variación" -> 3 elementos idénticos, per la
        // regla ya existente de ExecutionReportService::buildPrompt().
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => null, 'not_performed' => false,
                'sets' => [
                    ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                ],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'Listo, 3 series de 10');

    $log = ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first();
    expect($log)->not->toBeNull();
    expect($log->exerciseSets)->toHaveCount(3);

    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'A cuál ejercicio te refieres'));
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '¿Cuántas series/repeticiones'));
});

it('a real transcribed audio message ("🎤 [AUDIO]: ...") is stripped of its prefix and resolved exactly like a text report', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Elevaciones de gemelos con mancuernas'],
        ['name' => 'Curl de bíceps con mancuernas de pie'],
    ]);

    Http::fake([
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.test/audio.ogg'], 200),
        'cdn.example.test/*' => Http::response('fake-audio-bytes', 200),
        'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'listo hice tres series y cada una de 10 repeticiones'], 200),
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => null, 'not_performed' => false,
                'sets' => [
                    ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                ],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, null, 'audio', 'media123');

    $log = ExerciseLog::where('workout_exercise_id', $workoutExercises[0]->id)->first();
    expect($log)->not->toBeNull();
    expect($log->exerciseSets)->toHaveCount(3);
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'A cuál ejercicio te refieres'));
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '2. *Curl de bíceps con mancuernas de pie*'));
});

// ── H16.2 Fase 1.2 — una sola intervención conversacional, en lenguaje
// natural, en vez de confirmación técnica + transición administrativa ────

it('H16.2 Fase 1.2 — a normal report produces exactly ONE natural confirmation+transition message, then the next card exactly once, never the previous one again', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Elevaciones de gemelos con mancuernas'],
        ['name' => 'Curl de bíceps con mancuernas de pie'],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => null, 'not_performed' => false,
                'sets' => [
                    ['reps' => 10, 'load' => 8, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => 8, 'duration_seconds' => null],
                    ['reps' => 10, 'load' => 8, 'duration_seconds' => null],
                ],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, '3 series, 10 repeticiones por serie, con 8kg');

    $textMessages = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body'))
        ->filter()
        ->values();

    // Exactamente 2 mensajes de texto: confirmación+transición YA unificada
    // en una sola intervención, seguida de la tarjeta del siguiente — nunca
    // 3 mensajes separados (confirmación / transición / tarjeta).
    expect($textMessages)->toHaveCount(2);

    $confirmation = $textMessages[0];
    expect($confirmation)->toContain('Registré');
    expect($confirmation)->toContain('3 series de 10 repeticiones con 8kg');
    expect($confirmation)->toContain('Elevaciones de gemelos con mancuernas');
    // Nunca notación técnica de log.
    expect($confirmation)->not->toContain('rep@');
    expect($confirmation)->not->toContain('✅');

    expect($textMessages[1])->toContain('2. *Curl de bíceps con mancuernas de pie*');

    // El ejercicio recién reportado nunca vuelve a entregarse como tarjeta.
    expect($textMessages->filter(fn ($t) => str_contains($t, '1. *Elevaciones de gemelos con mancuernas*')))->toHaveCount(0);
});

it('H16.2 Fase 1.2 — a "not_performed" report is confirmed neutrally, never celebrated, and still advances naturally', function () {
    $contact = readyTrainingContact();
    [, $workoutExercises] = makeSessionWithExercises($contact, [
        ['name' => 'Hip Thrust con mancuerna'],
        ['name' => 'Curl de bíceps con mancuernas de pie'],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(reportExtractionBody([
            'reports' => [[
                'exercise_name' => null, 'not_performed' => true, 'skip_reason' => 'cant_do',
                'sets' => [], 'rpe_number' => null, 'rpe_category' => null,
                'note' => 'me dolió la rodilla', 'uncertain' => false,
            ]],
            'session_finished' => false,
        ]), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendMessageAsContact($contact, 'No pude hacerlo, me dolió la rodilla');

    $confirmation = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body'))
        ->filter()
        ->values()
        ->first();

    expect($confirmation)->toContain('no realizaste Hip Thrust con mancuerna');
    // Nunca felicita ni afirma progreso sobre un ejercicio no realizado.
    expect($confirmation)->not->toContain('¡Perfecto!');
    expect($confirmation)->not->toContain('Buen trabajo');
    expect($confirmation)->not->toContain('progres');
});
