<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * P1-A — Nudge por ejercicio no reportado. Cubre el comando
 * `training:nudge-unreported-exercises` (App\Console\Commands\
 * NudgeUnreportedExercises → App\Training\Support\UnreportedExerciseDetector
 * → App\Training\Support\ProactivityGate → CustomerNotifier), y confirma que
 * el comportamiento conversacional existente (ConversationTurnResolver/
 * ExecutionReportRecorder) sigue funcionando exactamente igual antes y
 * después de un nudge.
 *
 * `openWindowFor()`/`nudgeReportTurn()`/`nudgeSendTrainingMessage()` son
 * copias locales, con nombres propios (prefijo "nudge"), de patrones ya
 * usados en tests/Feature/Core/CustomerNotifierTest.php y
 * tests/Feature/Training/TrainingConversationFlowTest.php — Pest ejecuta
 * todos los archivos de test en el mismo proceso PHP, así que dos funciones
 * globales con el mismo nombre en dos archivos provocarían un fatal error al
 * correr la suite completa; esto mantiene este archivo ejecutable también en
 * aislamiento (`php artisan test tests/Feature/Training/NudgeUnreportedExercisesTest.php`).
 */
function nudgeOpenWindowFor(Tenant $tenant, string $phone): void
{
    Conversation::create(['tenant_id' => $tenant->id, 'customer_phone' => $phone, 'last_session_at' => now()->subHour()]);
}

/**
 * Construye Contact + WorkoutSession (scheduled) + N WorkoutExercise en
 * orden, sin ExerciseLog, con el delivered_at que cada test necesite. No
 * abre la ventana de 24h por defecto (las pruebas de nudge la abren
 * explícitamente cuando corresponde probar mensaje libre vs. plantilla).
 */
function nudgeMakeContact(Tenant $tenant, string $phone): Contact
{
    return Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => $phone, 'customer_name' => 'Ana']);
}

function nudgeSendTrainingMessage(Tenant $tenant, string $from, string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

/**
 * Forma mínima válida para ExecutionReportService::parseJson() — cubre solo
 * las claves que cada test necesita, dejando el resto en sus defaults
 * neutros (null/[]/false).
 */
function nudgeReportTurn(array $reports = [], array $intents = [], ?string $trainingReply = null, bool $sessionFinished = false): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'safety_signal_text' => null,
        'reports' => $reports,
        'session_finished' => $sessionFinished,
        'intents' => $intents,
        'training_reply' => $trainingReply,
        'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
    ])]]]];
}

it('does not nudge an exercise delivered less than the tenant threshold ago', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(10)]);

    Http::fake();
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertNothingSent();
});

it('nudges an exercise delivered more than the tenant threshold ago and still unreported', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    nudgeOpenWindowFor($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(40)]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertSentCount(1);
    expect(WhatsAppMessage::where('idempotency_key', 'like', 'exercise_nudge:%')->count())->toBe(1);
});

it('respects exercise_nudge_enabled = false, even past the threshold', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30, 'exercise_nudge_enabled' => false]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(40)]);

    Http::fake();
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertNothingSent();
});

it('uses the exact exercise_nudge_after_minutes configured on the tenant, not a hardcoded value', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 10]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    nudgeOpenWindowFor($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    // 12 minutos > umbral de 10 configurado para ESTE tenant (el default de
    // la columna es 30 — si el detector ignorara la config del tenant y
    // usara 30 fijo, esto no dispararía nudge).
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(12)]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertSentCount(1);
});

it('two tenants can have independent thresholds in the same run', function () {
    $tenantA = Tenant::factory()->create(['exercise_nudge_after_minutes' => 15]);
    $tenantB = Tenant::factory()->create(['exercise_nudge_after_minutes' => 60]);
    $contactA = nudgeMakeContact($tenantA, '573001112233');
    $contactB = nudgeMakeContact($tenantB, '573001112244');
    nudgeOpenWindowFor($tenantA, '573001112233');
    nudgeOpenWindowFor($tenantB, '573001112244');

    $sessionA = WorkoutSession::factory()->create(['contact_id' => $contactA->id]);
    $sessionB = WorkoutSession::factory()->create(['contact_id' => $contactB->id]);
    // 20 minutos: supera el umbral de A (15) pero no el de B (60).
    WorkoutExercise::factory()->create(['workout_session_id' => $sessionA->id, 'order' => 1, 'delivered_at' => now()->subMinutes(20)]);
    WorkoutExercise::factory()->create(['workout_session_id' => $sessionB->id, 'order' => 1, 'delivered_at' => now()->subMinutes(20)]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), '573001112233'));
});

it('does not nudge an exercise that already has an ExerciseLog', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    $exercise = WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(40)]);
    ExerciseLog::factory()->create(['workout_exercise_id' => $exercise->id]);

    Http::fake();
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertNothingSent();
});

it('does not nudge an exercise that was never delivered (delivered_at is null)', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => null]);

    Http::fake();
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertNothingSent();
});

it('does not nudge the second exercise while the first one is still pending, even if its delivered_at is also old', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(40)]);
    // Escenario defensivo: el segundo NUNCA debería tener delivered_at bajo
    // el flujo real (progresividad), pero el detector debe seguir
    // identificando al PRIMERO como el pendiente real, no a este.
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 2, 'delivered_at' => now()->subMinutes(40)]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    nudgeOpenWindowFor($tenant, '573001112233');
    Artisan::call('training:nudge-unreported-exercises');

    // Exactamente un nudge (el del ejercicio 1), nunca dos.
    Http::assertSentCount(1);
});

it('once the first exercise is reported, the second can receive its own nudge after its own delivered_at', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    nudgeOpenWindowFor($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    $first = WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subHours(2)]);
    ExerciseLog::factory()->create(['workout_exercise_id' => $first->id]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 2, 'delivered_at' => now()->subMinutes(40)]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertSentCount(1);
});

it('never sends a second nudge for the same WorkoutExercise across repeated scheduler runs', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    nudgeOpenWindowFor($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(40)]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    Artisan::call('training:nudge-unreported-exercises');
    Artisan::call('training:nudge-unreported-exercises');
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertSentCount(1);
});

it('concurrent execution of the same candidate never produces two confirmed nudges', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    nudgeOpenWindowFor($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    $exercise = WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(40)]);

    // Simula que OTRO proceso ya reclamó este mismo candidato justo antes de
    // que este arranque — mismo Cache::add() atómico que usa el Command.
    Cache::add("exercise_nudge_claim:{$exercise->id}", true, now()->addMinutes(5));

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertNothingSent();
});

it('after a nudge, a real report from the user still advances normally to the next exercise', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30, 'ai_provider' => 'openai']);
    $contact = nudgeMakeContact($tenant, '573001112233');
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    nudgeOpenWindowFor($tenant, '573001112233');

    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    // prescribed_sets=1: el reporte de abajo envía exactamente 1 serie —
    // con el default de la factory (3), ExecutionReportRecorder trataría
    // esto como un reporte PARCIAL (pregunta si continúa) en vez de avanzar.
    $first = WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'prescribed_sets' => 1, 'delivered_at' => now()->subMinutes(40)]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 2, 'delivered_at' => null]);

    // 1. Se dispara el nudge (no altera el estado conversacional).
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');
    Http::assertSentCount(1);

    // 2. El usuario responde reportando el ejercicio pendiente — mismo
    // comportamiento de ConversationTurnResolver/ExecutionReportRecorder
    // que sin ningún nudge de por medio.
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(nudgeReportTurn(
            reports: [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null, 'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]], 'rpe_number' => 7, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
        ), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT2']]], 200),
    ]);
    nudgeSendTrainingMessage($tenant, '573001112233', '3 series de 10 con 40kg');

    expect($first->fresh()->exerciseLog)->not->toBeNull();
    $second = WorkoutExercise::where('workout_session_id', $session->id)->where('order', 2)->first();
    expect($second->fresh()->delivered_at)->not->toBeNull(); // se entregó el siguiente
});

it('after a nudge, an unrelated question does not clear the pending exercise', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30, 'ai_provider' => 'openai']);
    $contact = nudgeMakeContact($tenant, '573001112233');
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    nudgeOpenWindowFor($tenant, '573001112233');

    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    $first = WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(40)]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(nudgeReportTurn(
            reports: [],
            intents: ['exercise_question'],
            trainingReply: '40kg es un buen punto de partida para ese ejercicio.',
        ), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT3']]], 200),
    ]);
    nudgeSendTrainingMessage($tenant, '573001112233', '¿esa carga está bien?');

    // El ejercicio sigue exactamente pendiente — nunca se marcó como
    // reportado solo porque el usuario escribió algo.
    expect($first->fresh()->exerciseLog)->toBeNull();
});

it('uses event_key = exercise_nudge and, with the window closed, delegates to the WhatsAppTemplate mechanism', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    // Ventana cerrada a propósito: sin Conversation en absoluto.
    WhatsAppTemplate::create([
        'tenant_id' => $tenant->id,
        'name' => 'exercise_nudge_v1',
        'event_key' => 'exercise_nudge',
        'body_preview' => 'Recordatorio de tu ejercicio pendiente.',
        'parameters_map' => [],
        'language' => 'es_CO',
        'type' => 'utility',
    ]);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(40)]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertSent(fn ($request) => $request['type'] === 'template' && $request['template']['name'] === 'exercise_nudge_v1');
});

it('resolves {{1}} in the approved exercise_nudge template to the contact real name via parameters_map', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    // nudgeMakeContact() fija customer_name='Ana' — es exactamente el valor
    // que debe aparecer en {{1}}, resuelto por CustomerNotifier::
    // resolveVariables() a partir de 'customer_name' en el arreglo de
    // variables que NudgeUnreportedExercises ahora provee — mismo mecanismo
    // ya usado por WhatsAppController::sendManualTemplate()/Payments, sin
    // ningún cambio en CustomerNotifier.
    $contact = nudgeMakeContact($tenant, '573001112233');
    WhatsAppTemplate::create([
        'tenant_id' => $tenant->id,
        'name' => 'exercise_nudge_v1',
        'event_key' => 'exercise_nudge',
        'body_preview' => '¿Pudiste completar este ejercicio, {{1}}? Cuando termines, envíame tu reporte para continuar con el siguiente.',
        'parameters_map' => ['1' => 'customer_name'],
        'language' => 'es_CO',
        'type' => 'utility',
    ]);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(40)]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertSent(fn ($request) => $request['type'] === 'template'
        && $request['template']['name'] === 'exercise_nudge_v1'
        && $request['template']['components'][0]['parameters'][0]['text'] === 'Ana');
});

it('with the window closed and no exercise_nudge template configured, never sends via any alternative channel', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    // Ventana cerrada, sin WhatsAppTemplate para exercise_nudge.
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(40)]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertNothingSent();
});

// ── delivered_at: integración con el flujo real de entrega (no solo el nudge) ──

it('delivered_at is set on the first exercise exactly when the session is delivered, and stays null on the rest', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = nudgeMakeContact($tenant, '573001112233');
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'available_equipment' => [], 'health_screening_asked' => true]);
    Exercise::factory()->count(2)->create(['muscle_group' => 'chest']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'safety_signal_text' => null, 'intents' => ['continue_training'], 'training_reply' => null,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    nudgeSendTrainingMessage($tenant, '573001112233', 'Dame mi entrenamiento de hoy');

    $session = WorkoutSession::where('contact_id', $contact->id)->firstOrFail();
    $exercises = WorkoutExercise::where('workout_session_id', $session->id)->orderBy('order')->get();

    expect($exercises->first()->delivered_at)->not->toBeNull();
    foreach ($exercises->slice(1) as $notYetDelivered) {
        expect($notYetDelivered->delivered_at)->toBeNull();
    }
});

it('delivered_at is set on the second exercise only at the moment it is actually advanced to, not before', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = nudgeMakeContact($tenant, '573001112233');
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'available_equipment' => [], 'health_screening_asked' => true]);
    Exercise::factory()->count(2)->create(['muscle_group' => 'chest']);

    // Un solo Http::fake() para todo el test, con una secuencia para
    // api.openai.com: un segundo Http::fake() a mitad de test NO reemplaza
    // el primero — Illuminate\Http\Client\Factory::stubUrl() ACUMULA los
    // stubs (los agrega a stubCallbacks vía merge(), nunca lo vacía) y el
    // primero registrado gana el match — verificado en el código real de
    // vendor/laravel/framework (Laravel 13.5.0) tras encontrar este mismo
    // comportamiento de forma empírica.
    $sets = array_fill(0, 5, ['reps' => 10, 'load' => 40, 'duration_seconds' => null]);
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode([
                'safety_signal_text' => null, 'intents' => ['continue_training'], 'training_reply' => null,
            ])]]]], 200)
            // 5 series idénticas: deliberadamente por encima de cualquier
            // prescripción por defecto de TrainingEngine (GOAL_DEFAULTS va
            // de 3 a 4 según el goal aleatorio del profile) — evita caer en
            // la rama de "reporte parcial" (menos series que las
            // prescritas), que no avanza.
            ->push(nudgeReportTurn(
                reports: [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null, 'sets' => $sets, 'rpe_number' => 7, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
            ), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    nudgeSendTrainingMessage($tenant, '573001112233', 'Dame mi entrenamiento de hoy');

    $session = WorkoutSession::where('contact_id', $contact->id)->firstOrFail();
    $second = WorkoutExercise::where('workout_session_id', $session->id)->where('order', 2)->firstOrFail();
    expect($second->delivered_at)->toBeNull();

    $beforeAdvance = now();
    nudgeSendTrainingMessage($tenant, '573001112233', '5 series de 10 con 40kg');

    expect($second->fresh()->delivered_at)->not->toBeNull();
    // gte() con 1 segundo de margen: `delivered_at` es una columna
    // `timestamp` (precisión de segundo completo en MySQL) — los
    // microsegundos de `now()` se truncan al persistir, así que comparar
    // contra un `$beforeAdvance` capturado con microsegundos puede fallar
    // por una fracción de segundo aunque el valor sea correcto. Un margen de
    // 1s sigue distinguiendo claramente "ahora" de "hace varios minutos"
    // (que es lo único que esta prueba necesita demostrar).
    expect($second->fresh()->delivered_at->gte($beforeAdvance->subSecond()))->toBeTrue();
});

it('never calls any AI provider to compose the nudge', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30, 'ai_provider' => 'openai']);
    $contact = nudgeMakeContact($tenant, '573001112233');
    nudgeOpenWindowFor($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1, 'delivered_at' => now()->subMinutes(40)]);

    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
});

// ── Hito R1/R2/R3 — R2/R3 nunca generan nudge ──────────────────────────────

it('never nudges a delivered-and-past-threshold Preparation/Cooldown exercise — it never requires an ExerciseLog', function (\App\Training\Enums\WorkoutExercisePhase $phase) {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    nudgeOpenWindowFor($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    // Muy por encima del umbral y sin ExerciseLog (nunca lo tendrá, por
    // diseño) — bajo el criterio pre-hito ("sin ExerciseLog") esto sería
    // candidato; UnreportedExerciseDetector lo excluye explícitamente por
    // no exigir reporte estructurado (ver requiresExecutionReport()).
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'order' => 1, 'phase' => $phase, 'delivered_at' => now()->subMinutes(40),
    ]);

    Http::fake();
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertNothingSent();
})->with([
    'Preparation' => [\App\Training\Enums\WorkoutExercisePhase::Preparation],
    'Cooldown' => [\App\Training\Enums\WorkoutExercisePhase::Cooldown],
]);

it('nudges the Main exercise past the threshold even when a Preparation exercise precedes it, already resolved', function () {
    $tenant = Tenant::factory()->create(['exercise_nudge_after_minutes' => 30]);
    $contact = nudgeMakeContact($tenant, '573001112233');
    nudgeOpenWindowFor($tenant, '573001112233');
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'order' => 1,
        'phase' => \App\Training\Enums\WorkoutExercisePhase::Preparation, 'delivered_at' => now()->subMinutes(45),
    ]);
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'order' => 2,
        'phase' => \App\Training\Enums\WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(40),
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Artisan::call('training:nudge-unreported-exercises');

    Http::assertSentCount(1);
});
