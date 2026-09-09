<?php

use App\CustomerCare\Models\CustomerServiceRequest;
use App\CustomerCare\Models\Faq;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AlertLog;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\TrackingType;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\TrainingAccessAdministrationService;
use Illuminate\Support\Facades\Http;

/**
 * Hito 15 — Escenario B: Training/FAQ/Reminder/Customer Service dentro de
 * un recorrido comercial REAL (TrainingAccess=Trial, otorgado por el
 * propio mecanismo automático de Hito 15) — no una sesión aislada por
 * factory como en H14. Reutiliza el contrato ya probado en H14
 * (TrainingHandlerInterruptionTest) sin repetirlo; aquí se verifica que
 * esas capacidades funcionan igual cuando el acceso viene del recorrido
 * comercial real y sobreviven intactas al ciclo completo.
 */
function commercialInteractionMessage(Tenant $tenant, string $from, ?string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

it('Training -> FAQ -> Customer Service, all during a real automatically-granted Trial, without corrupting the WorkoutSession or the Trial itself', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001150001']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    $access = app(TrainingAccessAdministrationService::class)->grantAutomaticTrial($contact, 5);

    $exercise = Exercise::factory()->create(['name' => 'Sentadilla', 'tracking_type' => TrackingType::RepsAndLoad]);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $workoutExercise = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(), 'prescribed_sets' => 3, 'prescribed_reps' => 10, 'prescribed_load' => 40,
    ]);
    $log = \App\Models\ExerciseLog::factory()->create(['workout_exercise_id' => $workoutExercise->id]);
    \App\Models\ExerciseSet::factory()->create(['exercise_log_id' => $log->id]); // sesión "en curso", todo ya reportado -> pasa por Coach, no ExecutionReportService

    $faq = Faq::factory()->create(['tenant_id' => $tenant->id, 'question' => '¿Cuál es el horario?', 'answer' => 'Abrimos de 6am a 9pm.']);

    // Turno 1: pregunta de FAQ.
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'safety_signal_text' => null, 'intents' => ['faq_question'], 'training_reply' => null,
            'faq_match_id' => $faq->id, 'faq_response_text' => 'Atendemos de 6am a 9pm.',
            'customer_service_needed' => false, 'customer_service_message' => null,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);
    commercialInteractionMessage($tenant, '573001150001', '¿cuál es el horario?');
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Atendemos de 6am a 9pm.'));

    // Turno 2: Customer Service explícito.
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'safety_signal_text' => null, 'intents' => ['customer_service_request'], 'training_reply' => null,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT2']]], 200),
    ]);
    commercialInteractionMessage($tenant, '573001150001', 'Tengo un tema que no logro resolver, ¿me pueden orientar?');
    expect(CustomerServiceRequest::where('contact_id', $contact->id)->exists())->toBeTrue();
    expect(AlertLog::where('category', 'customer_service')->exists())->toBeTrue();

    // El Trial/sesión siguen exactamente intactos — ninguna interrupción
    // los tocó.
    expect($access->fresh()->status)->toBe(\App\Training\Enums\TrainingAccessStatus::Trial);
    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect($workoutExercise->fresh()->exerciseLog)->not->toBeNull();
});

it('a reply after a fired Reminder can naturally continue into Training, without the Scheduler/Queue interfering with the commercial state', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001150002']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    app(TrainingAccessAdministrationService::class)->grantAutomaticTrial($contact, 5);

    $reminder = \App\Models\Reminder::factory()->oneOff()->create([
        'contact_id' => $contact->id,
        'tenant_id' => $tenant->id,
        'status' => \App\Training\Enums\ReminderStatus::Pending,
        'fire_at' => now()->subMinute(),
    ]);
    // Sin esto la ventana de 24h está cerrada (sin Conversation) y, sin
    // ningún WhatsAppTemplate configurado para el evento del recordatorio,
    // CustomerNotifier no envía nada — mismo patrón ya usado en los tests
    // de Payments para mantener la ventana abierta.
    \App\Models\Conversation::create(['tenant_id' => $tenant->id, 'customer_phone' => $contact->customer_phone, 'last_session_at' => now()->subMinutes(5)]);

    // Un solo Http::fake() para todo el test — el disparo del recordatorio
    // en sí redacta su texto vía IA (TrainingReminderExecutor), así que
    // necesita su propio stub de api.openai.com además del de Meta.
    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '💪 ¡Hoy toca entrenar! Responde "sí" y preparo tu rutina.']]]]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    // El Scheduler real dispara el recordatorio (mismo comando que corre en
    // producción) — no debe alterar TrainingAccess ni Payment/Referral. El
    // ciclo de vida completo del envío (Pending -> Sending -> Sent) ya está
    // probado a fondo en ReminderSchedulerTest — aquí solo importa que el
    // disparo avanzó (dejó de estar Pending) y no tocó el estado comercial.
    \Illuminate\Support\Facades\Artisan::call('reminders:dispatch-due');

    expect($reminder->fresh()->status)->toBe(\App\Training\Enums\ReminderStatus::Sent);
    expect(TrainingAccess::where('contact_id', $contact->id)->sole()->status)->toBe(\App\Training\Enums\TrainingAccessStatus::Trial);

    // La respuesta corta ("sí") dentro de la ventana de continuidad
    // continúa el flujo de Training de forma determinista, sin IA — nunca
    // cae a fallback_chat/otro dominio.
    commercialInteractionMessage($tenant, '573001150002', 'sí');

    $lastAssistantMessage = \App\Models\WhatsAppMessage::where('tenant_id', $tenant->id)
        ->where('customer_phone', '573001150002')->where('role', 'assistant')->latest('id')->first();
    expect($lastAssistantMessage)->not->toBeNull();
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1); // se generó/entregó la rutina
    expect(TrainingAccess::where('contact_id', $contact->id)->count())->toBe(1); // el Reminder no tocó el Trial
});
