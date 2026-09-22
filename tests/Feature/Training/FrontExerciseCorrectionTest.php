<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\ExecutionReportRecorder;
use Illuminate\Support\Facades\Http;

/**
 * Corrección post-incidente de staging (#33, hito R1/R2/R3) — cubre el
 * contrato FRONT EXERCISE / RESOLUTION / NEXT TO DELIVER establecido para
 * corregir la regresión: una confirmación de Preparation ("listo rodillas
 * altas") terminaba atribuyéndose a un Main que el usuario nunca vio, y el
 * mismo Preparation se reentregaba repetidamente. Helpers con prefijo
 * "fec" — propios de este archivo, mismo criterio que el resto de la
 * suite para evitar colisión de funciones globales entre archivos de test.
 */
function fecReadyContact(): Contact
{
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

/**
 * @param  array<int, array{name: string, phase: WorkoutExercisePhase, delivered_at: \Illuminate\Support\Carbon|null, logged: bool}>  $exercises
 * @return array{0: WorkoutSession, 1: array<int, WorkoutExercise>}
 */
function fecSession(Contact $contact, array $exercises): array
{
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $created = [];

    foreach ($exercises as $i => $spec) {
        $exercise = Exercise::factory()->create(['name' => $spec['name']]);
        $we = WorkoutExercise::factory()->create([
            'workout_session_id' => $session->id,
            'exercise_id' => $exercise->id,
            'exercise_snapshot' => $exercise->toSnapshot(),
            'order' => $i + 1,
            'phase' => $spec['phase'],
            'prescribed_sets' => $spec['phase'] === WorkoutExercisePhase::Main ? 3 : 1,
            'prescribed_reps' => $spec['phase'] === WorkoutExercisePhase::Main ? 10 : null,
            'prescribed_load' => null,
            'prescribed_duration_seconds' => $spec['phase'] === WorkoutExercisePhase::Main ? null : 90,
            'rest_seconds' => $spec['phase'] === WorkoutExercisePhase::Main ? 60 : 0,
            'delivered_at' => $spec['delivered_at'],
        ]);

        if ($spec['logged'] ?? false) {
            ExerciseLog::factory()->create(['workout_exercise_id' => $we->id]);
        }

        $created[] = $we;
    }

    return [$session, $created];
}

function fecSendMessage(Contact $contact, string $body): void
{
    $job = new ProcessWhatsAppMessage($contact->tenant, $contact->customer_phone, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

function fecCoachTurn(array $intents, ?string $trainingReply = null): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'safety_signal_text' => null, 'intents' => $intents, 'training_reply' => $trainingReply,
        'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        'faq_match_id' => null, 'faq_response_text' => null, 'customer_service_needed' => false, 'customer_service_message' => null,
        'conversation_reinforcement_included' => false,
    ])]]]];
}

function fecReportTurn(array $reports = [], array $intents = []): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'safety_signal_text' => null, 'reports' => $reports, 'session_finished' => false, 'intents' => $intents,
        'training_reply' => null, 'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null,
        'reminder_confirmation' => null,
    ])]]]];
}

function fecOutboundBodies(): \Illuminate\Support\Collection
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body', ''));
}

// ─────────────────────────────────────────────────────────────────────────
// FRONT — WorkoutSession::frontExercise()
// ─────────────────────────────────────────────────────────────────────────

it('front: ningún ejercicio entregado devuelve null', function () {
    $contact = fecReadyContact();
    [$session] = fecSession($contact, [
        ['name' => 'Rodillas altas', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => null],
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null],
    ]);

    expect($session->fresh('workoutExercises')->frontExercise())->toBeNull();
});

it('front: Preparation entregado devuelve ese Preparation', function () {
    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Rodillas altas', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => now()],
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null],
    ]);

    $front = $session->fresh('workoutExercises')->frontExercise();
    expect($front->id)->toBe($we[0]->id);
    expect($front->phase)->toBe(WorkoutExercisePhase::Preparation);
});

it('front: Main entregado devuelve ese Main', function () {
    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()],
        ['name' => 'Zancadas', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null],
    ]);

    $front = $session->fresh('workoutExercises')->frontExercise();
    expect($front->id)->toBe($we[0]->id);
    expect($front->phase)->toBe(WorkoutExercisePhase::Main);
});

it('front: Cooldown entregado devuelve ese Cooldown', function () {
    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(5), 'logged' => true],
        ['name' => 'Estiramiento', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => now()],
    ]);

    $front = $session->fresh('workoutExercises')->frontExercise();
    expect($front->id)->toBe($we[1]->id);
    expect($front->phase)->toBe(WorkoutExercisePhase::Cooldown);
});

it('front: con varios entregados, devuelve el de mayor order — nunca exerciseLog/isResolvedForSessionProgression', function () {
    $contact = fecReadyContact();
    // Preparation entregado y confirmado hace turnos (ya no es el frente),
    // Main entregado y AÚN sin log (el frente real) — replica exactamente
    // el estado del incidente de staging tras la primera confirmación.
    [$session, $we] = fecSession($contact, [
        ['name' => 'Rodillas altas', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => now()->subMinutes(5)],
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()],
        ['name' => 'Zancadas', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null],
    ]);

    $front = $session->fresh('workoutExercises')->frontExercise();
    expect($front->id)->toBe($we[1]->id); // Sentadilla — el de mayor order entregado, NUNCA el Preparation
});

// ─────────────────────────────────────────────────────────────────────────
// NEXT TO DELIVER — WorkoutSession::nextUndeliveredExercise()
// ─────────────────────────────────────────────────────────────────────────

it('next: ninguno entregado devuelve el primer WorkoutExercise', function () {
    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Rodillas altas', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => null],
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null],
    ]);

    expect($session->fresh('workoutExercises')->nextUndeliveredExercise()->id)->toBe($we[0]->id);
});

it('next: Preparation entregado devuelve el siguiente Main', function () {
    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Rodillas altas', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => now()],
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null],
    ]);

    expect($session->fresh('workoutExercises')->nextUndeliveredExercise()->id)->toBe($we[1]->id);
});

it('next: Main resuelto (con log) pero sin delivered_at en el siguiente devuelve ese siguiente WorkoutExercise', function () {
    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now(), 'logged' => true],
        ['name' => 'Zancadas', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null],
    ]);

    expect($session->fresh('workoutExercises')->nextUndeliveredExercise()->id)->toBe($we[1]->id);
});

it('next: Support entregado devuelve el siguiente WorkoutExercise', function () {
    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(5), 'logged' => true],
        ['name' => 'Estiramiento', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => now()],
        ['name' => 'Respiración', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => null],
    ]);

    expect($session->fresh('workoutExercises')->nextUndeliveredExercise()->id)->toBe($we[2]->id);
});

it('next: nunca devuelve un ejercicio con delivered_at no nulo', function () {
    $contact = fecReadyContact();
    [$session] = fecSession($contact, [
        ['name' => 'Rodillas altas', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => now()],
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now(), 'logged' => true],
        ['name' => 'Zancadas', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()],
    ]);

    $next = $session->fresh('workoutExercises')->nextUndeliveredExercise();
    expect($next)->toBeNull(); // los 3 ya están entregados — nunca reselecciona ninguno
});

// ─────────────────────────────────────────────────────────────────────────
// MAIN — reporte atribuido correctamente (Reglas 3/4/5)
// ─────────────────────────────────────────────────────────────────────────

it('Main: un reporte implícito (sin nombre) se atribuye al front Main real, vía ExecutionReportRecorder::record()', function () {
    $session = WorkoutSession::factory()->create(['status' => WorkoutSessionStatus::Scheduled]);
    $exercise = Exercise::factory()->create(['name' => 'Sentadilla']);
    $front = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'order' => 1, 'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 1,
    ]);

    $outcome = (new ExecutionReportRecorder)->record($session, [
        'reports' => [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
            'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
            'rpe' => null, 'note' => null, 'uncertain' => false]],
    ], frontExerciseId: $front->id);

    expect($outcome->logged)->toHaveCount(1);
    expect($front->fresh()->exerciseLog)->not->toBeNull();
});

it('Main: nunca reporta un Main NO entregado — sin front id, un reporte sin nombre no se atribuye a nadie', function () {
    $session = WorkoutSession::factory()->create(['status' => WorkoutSessionStatus::Scheduled]);
    $exercise = Exercise::factory()->create(['name' => 'Sentadilla']);
    // WE70 del incidente real: Main NUNCA entregado (delivered_at null).
    $undelivered = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'order' => 1, 'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 1, 'delivered_at' => null,
    ]);

    $outcome = (new ExecutionReportRecorder)->record($session, [
        'reports' => [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
            'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
            'rpe' => null, 'note' => null, 'uncertain' => false]],
    ], frontExerciseId: null); // el frente real era un Preparation — nunca este Main

    expect($outcome->logged)->toBe([]);
    expect($outcome->clarifications)->not->toBe([]); // pide aclaración, nunca inventa el ejercicio
    expect($undelivered->fresh()->exerciseLog)->toBeNull();
});

it('Main: nunca selecciona un Preparation/Cooldown como destino de un reporte implícito, aunque sea el único WorkoutExercise sin log', function (WorkoutExercisePhase $phase) {
    $session = WorkoutSession::factory()->create(['status' => WorkoutSessionStatus::Scheduled]);
    $mainExercise = Exercise::factory()->create(['name' => 'Sentadilla']);
    $supportExercise = Exercise::factory()->create(['name' => 'Rodillas altas']);

    $main = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $mainExercise->id, 'exercise_snapshot' => $mainExercise->toSnapshot(),
        'order' => 1, 'phase' => WorkoutExercisePhase::Main, 'prescribed_sets' => 1,
    ]);
    ExerciseLog::factory()->create(['workout_exercise_id' => $main->id]); // el único Main ya tiene log
    $support = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $supportExercise->id, 'exercise_snapshot' => $supportExercise->toSnapshot(),
        'order' => 2, 'phase' => $phase, 'prescribed_sets' => 1, 'delivered_at' => now(),
    ]);

    $outcome = (new ExecutionReportRecorder)->record($session, [
        'reports' => [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
            'sets' => [], 'rpe' => null, 'note' => null, 'uncertain' => false]],
    ], frontExerciseId: $support->id); // aunque se pase (por error) el id del support...

    // ...nunca se le crea un ExerciseLog: $unreported (candidatos) solo
    // contiene Main, así que el "first(id === frontExerciseId)" no lo
    // encuentra — la protección es estructural, no solo de intención.
    expect($support->fresh()->exerciseLog)->toBeNull();
})->with([
    'Preparation' => [WorkoutExercisePhase::Preparation],
    'Cooldown' => [WorkoutExercisePhase::Cooldown],
]);

// ─────────────────────────────────────────────────────────────────────────
// COMPLETION — exactamente una vez, autoridad única
// ─────────────────────────────────────────────────────────────────────────

it('completion: completa exactamente una vez, nunca dos WorkoutSessionCompleted', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Training\Events\WorkoutSessionCompleted::class]);

    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(5)],
        ['name' => 'Estiramiento', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => null],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(fecReportTurn(
            reports: [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
                'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null], ['reps' => 10, 'load' => 40, 'duration_seconds' => null], ['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
        )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    fecSendMessage($contact, '3 series de 10 con 40kg');

    \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Training\Events\WorkoutSessionCompleted::class, 1);
    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Completed);
});

// ─────────────────────────────────────────────────────────────────────────
// CONTINUE_TRAINING — contrato de sesión activa, PASO 5 real (Regla 11)
//
// Ambos tests siguientes están construidos para que sea ESTRUCTURALMENTE
// IMPOSIBLE que el turno pase por el paso 4 (ExecutionReportService):
// `unreported_exercises` (Main + exerciseLog===null, sin mirar
// delivered_at — ver ActiveWorkoutSessionContextProvider::provide(), sin
// cambios) debe quedar VACÍO. Dado que `order` es global y continuo
// (Preparation → Main → Cooldown, TrainingEngine::decideNextSession(),
// sin cambios) y que un Main solo puede tener ExerciseLog si fue el
// frente alguna vez (exige delivered_at), es IMPOSIBLE que el frente sea
// un Preparation mientras todos los Main tengan log — cualquier Main ya
// entregado tendría `order` menor que ese Preparation, contradicción. La
// única forma real de tener reportableExercises=[] con un frente de
// apoyo es: frente = Cooldown, con TODOS los Main ya logueados. Por eso
// ambos fixtures usan Cooldown como frente, nunca Preparation.
// ─────────────────────────────────────────────────────────────────────────

it('continue_training (paso 5 real): front=Cooldown con el único Main ya logueado y reportableExercises vacío — Coach clasifica continue_training, se entrega nextUndeliveredExercise(), nunca se repite el front ni se reenvía la intro', function () {
    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Rodillas altas', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => now()->subMinutes(10)],
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(8), 'logged' => true],
        ['name' => 'Estiramiento de isquiotibiales', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => now()->subMinutes(1)],
        ['name' => 'Respiración guiada', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => null],
        // Un Cooldown adicional, todavía sin entregar, DESPUÉS de
        // $expectedNext: sin este, $expectedNext sería el último
        // WorkoutExercise de la sesión y su entrega completaría la sesión
        // legítimamente (deliverExerciseAndMaybeComplete() — Support +
        // isResolvedForSessionProgression()=true tras entregar el último
        // pendiente, comportamiento real sin relación con este hito). Este
        // test quiere aislar específicamente "se entregó el siguiente y la
        // sesión SIGUE activa", así que el fixture deja un pendiente más.
        ['name' => 'Respiración final', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => null],
    ]);
    [$prep, $main, $cooldownFront, $expectedNext, $afterNext] = $we;

    // ── Verificación EXPLÍCITA del fixture, ANTES de cualquier aserción
    // de salida — el objetivo de este test es demostrar la ruta real del
    // paso 5, así que primero se confirma que el fixture realmente la
    // fuerza (si esto falla, el test de abajo no probaría nada real).
    $freshSession = $session->fresh('workoutExercises');
    expect($freshSession->frontExercise()->id)->toBe($cooldownFront->id);
    expect($freshSession->nextUndeliveredExercise()->id)->toBe($expectedNext->id);
    expect($main->fresh()->exerciseLog)->not->toBeNull(); // el único Main entregado tiene ExerciseLog

    // No existe ningún Main reportable pendiente — mismo criterio exacto
    // que ActiveWorkoutSessionContextProvider::provide() usa para
    // `unreported_exercises`, verificado aquí directamente sobre el
    // modelo (sin invocar el proveedor, para no acoplar el fixture a esa
    // implementación).
    $pendingMain = $freshSession->workoutExercises
        ->filter(fn (WorkoutExercise $x) => $x->requiresExecutionReport() && $x->exerciseLog === null);
    expect($pendingMain)->toBeEmpty();

    // El mensaje NO es reconocido por el detector determinista (ni exacto
    // ni por prefijo) — el paso 4a tampoco intercepta este turno.
    expect((new \App\Training\Support\SupportPhaseConfirmationDetector)->isExplicitConfirmation('lo dejo ahí'))->toBeFalse();

    // Como `reportableExercises` queda vacío por construcción, el paso 4
    // (`$reportableExercises !== []`) es estructuralmente inalcanzable —
    // la ÚNICA llamada de IA posible en este turno es la de Coach (paso 5).
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(fecCoachTurn(['continue_training'])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    fecSendMessage($contact, 'lo dejo ahí');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.openai.com')); // Coach SÍ fue invocado
    $bodies = fecOutboundBodies();

    // 1) Se entrega $expectedNext.
    expect($bodies->contains(fn ($b) => str_contains($b, 'Respiración guiada')))->toBeTrue();
    expect($expectedNext->fresh()->delivered_at)->not->toBeNull();

    // 2) NO se vuelve a entregar el Cooldown/front.
    expect($bodies->filter(fn ($b) => str_contains($b, 'Estiramiento de isquiotibiales'))->count())->toBe(0);

    // 3) NO se entrega el primer ejercicio de la sesión (Preparation).
    expect($bodies->filter(fn ($b) => str_contains($b, 'Rodillas altas'))->count())->toBe(0);

    // 4) NO se llama decideNextSession() — prueba distintiva real: como
    // decideNextSession() es idempotente, "sigue habiendo 1 sola sesión"
    // por sí solo NO distinguiría esta ruta de la incorrecta (si
    // executeTurnActions() hubiera recibido `null`, DeliverSession habría
    // marcado $shouldDeliverSession=true, caído al paso 6, y
    // decideNextSession() habría devuelto esta MISMA sesión igual). Lo
    // que SÍ distingue ambas rutas es que el paso 6 SIEMPRE envía la
    // intro antes de entregar — eso nunca ocurre aquí.
    expect($bodies->contains(fn ($b) => str_contains($b, '🔥 Tu entrenamiento de hoy')))->toBeFalse();

    // 5) Se conserva la misma sesión activa.
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect($afterNext->fresh()->delivered_at)->toBeNull(); // se entrega EXACTAMENTE $expectedNext, ni uno más

    // 6) La combinación de (1) + (4) es la prueba de que executeTurnActions()
    // recibió el fragmento REAL de sesión activa en el paso 5 (Regla 11):
    // solo esa rama entrega directamente sin pasar por la intro.
});

it('continue_training (paso 5 real), test mínimo: nextUndeliveredExercise() se obtiene por delivered_at/order, nunca por ExerciseLog', function () {
    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(10), 'logged' => true],
        ['name' => 'Zancadas', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(8), 'logged' => true],
        ['name' => 'Estiramiento', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => now()->subMinutes(1)],
        ['name' => 'Respiración guiada', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => null],
    ]);
    [$main1, $main2, $cooldownFront, $next] = $we;

    $freshSession = $session->fresh('workoutExercises');
    expect($freshSession->frontExercise()->id)->toBe($cooldownFront->id);
    expect($freshSession->nextUndeliveredExercise()->id)->toBe($next->id);

    // Prueba directa de que el criterio es delivered_at/order, NUNCA
    // ExerciseLog: $cooldownFront NUNCA tiene ExerciseLog (Preparation/
    // Cooldown nunca lo tienen, por diseño) — bajo el criterio ANTIGUO
    // (`whereDoesntHave('exerciseLog')->first()`, la deuda técnica ya
    // corregida en producción), la primera fila SIN log, ordenada por
    // `order`, habría sido $cooldownFront mismo (ya entregado) — nunca
    // $next. Este fixture es precisamente el que distingue ambos
    // criterios: $main1/$main2 SÍ tienen log, así que por sí solos no
    // bastarían para probar la diferencia.
    expect($cooldownFront->exerciseLog)->toBeNull();

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(fecCoachTurn(['continue_training'])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    fecSendMessage($contact, 'lo dejo ahí');

    $bodies = fecOutboundBodies();

    // Se entrega exactamente $next.
    expect($bodies->contains(fn ($b) => str_contains($b, 'Respiración guiada')))->toBeTrue();
    expect($next->fresh()->delivered_at)->not->toBeNull();

    // No se entrega ningún ejercicio anterior.
    expect($bodies->filter(fn ($b) => str_contains($b, 'Sentadilla'))->count())->toBe(0);
    expect($bodies->filter(fn ($b) => str_contains($b, 'Zancadas'))->count())->toBe(0);
    expect($bodies->filter(fn ($b) => str_contains($b, 'Estiramiento'))->count())->toBe(0);

    // No se crea una nueva sesión, no se repite la intro.
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
    expect($bodies->contains(fn ($b) => str_contains($b, '🔥 Tu entrenamiento de hoy')))->toBeFalse();
});

it('continue_training: sesión Completed se comporta como ausencia de sesión activa — genera una nueva legítimamente', function () {
    $contact = fecReadyContact();
    Exercise::factory()->create(['muscle_group' => 'chest', 'name' => 'Flexiones']);
    [$oldSession] = fecSession($contact, [
        ['name' => 'Sentadilla', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subHour(), 'logged' => true],
    ]);
    $oldSession->update(['status' => WorkoutSessionStatus::Completed, 'completed_at' => now()->subHour()]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(fecCoachTurn(['continue_training'])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    fecSendMessage($contact, 'dame mi rutina de hoy');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(2);
    $newSession = WorkoutSession::where('contact_id', $contact->id)->where('status', WorkoutSessionStatus::Scheduled)->sole();
    expect($newSession->id)->not->toBe($oldSession->id);
});

it('continue_training: sin ninguna sesión activa genera una sesión nueva', function () {
    $contact = fecReadyContact();
    Exercise::factory()->create(['muscle_group' => 'chest', 'name' => 'Flexiones']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(fecCoachTurn(['continue_training'])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    fecSendMessage($contact, 'quiero entrenar');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
    $bodies = fecOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, '🔥 Tu entrenamiento de hoy')))->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────
// REGRESIÓN EXACTA — reproduce conceptualmente el incidente de staging #33
// ─────────────────────────────────────────────────────────────────────────

it('regresión #33: Preparation -> confirmación -> Main -> reporte -> Main -> reporte -> siguiente Main, sin repetir Preparation ni contaminar Main no entregados', function () {
    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Rodillas altas', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => now()->subMinutes(3)],
        ['name' => 'Sentadillas sumo', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null],
        ['name' => 'QA Sentadilla BORRAR 1', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null],
        ['name' => 'Caminata de monstruo con banda', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null],
    ]);
    [$prep, $sentadillas, $qa, $caminata] = $we;

    // Registrado UNA sola vez para todo el test: llamadas sucesivas a
    // Http::fake() con el MISMO patrón de URL no reemplazan el stub
    // anterior en este proyecto (comportamiento verificado de Http::fake()
    // — no relacionado con la corrección de este hito) — el patrón
    // correcto para varias respuestas de IA en un mismo test es
    // Http::sequence(), consumida en orden por cada llamada real.
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            // 2) Reporte COMPLETO del Main real (front correcto: Sentadillas
            // sumo) — 3/3 series, sin clarificación pendiente, avanza
            // automáticamente.
            ->push(fecReportTurn(
                reports: [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
                    'sets' => [
                        ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                        ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                        ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                    ],
                    'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
            ))
            // 3) "No lo hice" — reporte implícito (sin nombre) sobre el
            // nuevo front real (QA Sentadilla BORRAR 1).
            ->push(fecReportTurn(
                reports: [['exercise_name' => null, 'not_performed' => true, 'skip_reason' => 'dont_want',
                    'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
            )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    // 1) "Listo rodillas altas" — confirmación EXPLÍCITA del Preparation
    // (prefijo aceptado tras la corrección) — determinista, sin IA (nunca
    // consume la secuencia de arriba).
    fecSendMessage($contact, 'Listo rodillas altas');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.openai.com'));
    expect($prep->fresh()->exerciseLog)->toBeNull(); // Preparation NUNCA tiene ExerciseLog
    expect($prep->fresh()->delivered_at)->not->toBeNull();
    expect($sentadillas->fresh()->delivered_at)->not->toBeNull(); // avanzó al primer Main real, nunca Sentadillas se confundió con Rodillas altas

    // 2) Reporte completo — consume el 1er elemento de la secuencia.
    fecSendMessage($contact, '3 series de 10 con 40kg');

    expect($sentadillas->fresh()->exerciseLog)->not->toBeNull(); // el log cae en Sentadillas sumo, no en otro Main
    expect($qa->fresh()->exerciseLog)->toBeNull();
    expect($caminata->fresh()->exerciseLog)->toBeNull();
    expect($qa->fresh()->delivered_at)->not->toBeNull(); // avanzó al siguiente Main real (QA), nunca reentregó Rodillas altas

    // 3) "No lo hice" — consume el 2do elemento de la secuencia. Nunca
    // contamina Caminata (el Main siguiente, todavía sin entregar) ni
    // reentrega Rodillas altas.
    fecSendMessage($contact, 'no lo hice');

    expect($qa->fresh()->exerciseLog)->not->toBeNull(); // se registró en QA, el front real de ese turno
    expect($qa->fresh()->exerciseLog->exerciseSets)->toHaveCount(0);
    expect($caminata->fresh()->exerciseLog)->toBeNull(); // Caminata (siguiente Main) nunca se contamina
    expect($caminata->fresh()->delivered_at)->not->toBeNull(); // avanzó correctamente al último Main

    // Refuerzo explícito (no reemplaza nada de arriba, solo lo hace a
    // prueba de falsos positivos por reutilización de stub): se inspecciona
    // directamente la respuesta HTTP que el turno 3 realmente consumió,
    // para demostrar que fue el 2do elemento de la secuencia
    // (not_performed=true, sets=[]) y no una reutilización del 1ro
    // (not_performed=false, con 3 sets) — que es exactamente el bug de
    // Http::fake() que este mismo test evitó al pasar a Http::sequence().
    $openaiCalls = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.openai.com'));
    expect($openaiCalls)->toHaveCount(2); // 1 por cada reporte real; el turno 1 (determinista) no llama IA
    $lastAiResponse = json_decode(
        data_get($openaiCalls->last()[1]->json(), 'choices.0.message.content'),
        true
    );
    expect($lastAiResponse['reports'][0]['not_performed'])->toBeTrue();
    expect($lastAiResponse['reports'][0]['sets'])->toBe([]);
    // Y, por contraste, la respuesta consumida en el turno 2 fue realmente
    // la OTRA (not_performed=false, 3 sets) — confirma que cada turno
    // consumió un elemento DISTINTO de la secuencia, en el orden correcto.
    $firstAiResponse = json_decode(
        data_get($openaiCalls->first()[1]->json(), 'choices.0.message.content'),
        true
    );
    expect($firstAiResponse['reports'][0]['not_performed'])->toBeFalse();
    expect($firstAiResponse['reports'][0]['sets'])->toHaveCount(3);

    // Verificación final — el CRITERIO FUNDAMENTAL del hito: Rodillas altas
    // (Preparation) ya estaba entregada ANTES de este turno (fixture,
    // simulando una entrega de un turno previo, como en el incidente real)
    // y NUNCA vuelve a reentregarse (ninguna tarjeta de técnica nueva) a lo
    // largo de los 3 mensajes — ni la confirmación, ni los 2 reportes
    // subsiguientes la tocan.
    $bodies = fecOutboundBodies();
    expect($bodies->filter(fn ($b) => str_contains($b, 'Rodillas altas'))->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────
// HALLAZGO ACOTADO (no corregido en este hito, ver reporte final) — una
// respuesta a una clarificación de reporte PARCIAL ("¿vas a hacer 1 serie
// más o lo dejas ahí?") ya no puede re-adjuntarse al MISMO Main parcial en
// un turno posterior (record() la trata como "ya tiene ExerciseLog" y la
// descarta como duplicado antes de llegar a resolveExercise() — mecanismo
// preexistente, sin cambios en este hito). Bajo el contrato ANTERIOR, ese
// mismo mensaje se atribuía SILENCIOSAMENTE a un Main distinto y AJENO
// (el bug real de staging). Bajo el contrato NUEVO, sin nombre explícito
// y sin que el front esté en el pool de candidatos, el sistema pide
// aclaración — nunca corrompe datos de otro ejercicio. Este test documenta
// el comportamiento SEGURO actual, no una resolución completa.
// ─────────────────────────────────────────────────────────────────────────

it('hallazgo acotado: una respuesta a una clarificación de reporte parcial nunca contamina un Main distinto, aunque no logre re-adjuntarse al mismo', function () {
    $contact = fecReadyContact();
    [$session, $we] = fecSession($contact, [
        ['name' => 'Sentadillas sumo', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(2)],
        ['name' => 'QA Sentadilla BORRAR 1', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null],
    ]);
    [$sentadillas, $qa] = $we;

    // Un solo Http::fake() con Http::sequence() para las 2 llamadas de IA
    // de este test — ver comentario equivalente en el test de regresión
    // #33 (llamadas repetidas a Http::fake() con el mismo patrón de URL no
    // se reemplazan entre sí en este proyecto).
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            // Reporte parcial (1/3 series) — genera clarificación, no avanza.
            ->push(fecReportTurn(
                reports: [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
                    'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
                    'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
            ))
            // "Lo dejo ahí" respondiendo a esa clarificación.
            ->push(fecReportTurn(
                reports: [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
                    'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
            )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    fecSendMessage($contact, '1 serie de 10 con 40kg');

    expect($qa->fresh()->delivered_at)->toBeNull(); // no avanzó — hay clarificación pendiente

    // Bajo el contrato nuevo, nunca se atribuye a QA (el otro Main, nunca
    // entregado).
    fecSendMessage($contact, 'lo dejo ahí');

    expect($qa->fresh()->exerciseLog)->toBeNull(); // CRÍTICO: nunca contamina el Main no entregado
});
