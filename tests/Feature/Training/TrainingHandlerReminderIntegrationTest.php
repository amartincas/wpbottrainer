<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Exercise;
use App\Models\Reminder;
use App\Models\ReminderSuggestion;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\ReminderStatus;
use App\Training\Enums\ReminderSuggestionStatus;
use App\Training\Enums\TrackingType;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Facades\Http;

/**
 * Hito 10 — integración end-to-end de Reminders a través del flujo real de
 * Bloque 9 (Router -> Dispatcher -> TrainingHandler), mismo patrón que
 * TrainingHandlerInterruptionTest.php.
 */
function reminderIntegrationContact(): Contact
{
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    Conversation::create(['tenant_id' => $tenant->id, 'customer_phone' => $contact->customer_phone, 'last_session_at' => now()->subMinutes(5)]);

    return $contact->fresh();
}

function reminderIntegrationSession(Contact $contact): array
{
    $exercise = Exercise::factory()->create(['name' => 'Sentadilla', 'tracking_type' => TrackingType::RepsAndLoad]);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $we = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 3, 'prescribed_reps' => 10, 'prescribed_load' => 40,
    ]);

    return [$session, $we];
}

function reminderChatBody(array $payload): array
{
    return ['choices' => [['message' => ['content' => json_encode($payload)]]]];
}

function sendReminderIntegrationMessage(Contact $contact, string $body): void
{
    $job = new ProcessWhatsAppMessage($contact->tenant, $contact->customer_phone, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

it('a reminder request without a pending session goes through Coach, creates a pending suggestion, never a Reminder', function () {
    $contact = reminderIntegrationContact();

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => ['reminder_request'], 'training_reply' => null,
            'reminder_day' => 'tuesday', 'reminder_time' => '19:00', 'reminder_recurrence' => true, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, 'Todos los martes recuérdame entrenar a las 7pm');

    expect(ReminderSuggestion::where('contact_id', $contact->id)->count())->toBe(1);
    expect(Reminder::where('contact_id', $contact->id)->count())->toBe(0);
    Http::assertSent(fn ($r) => str_contains(data_get($r->data(), 'text.body', ''), 'Confirmas'));
});

it('confirming a pending suggestion creates the Reminder and marks the suggestion accepted', function () {
    $contact = reminderIntegrationContact();
    ReminderSuggestion::create([
        'tenant_id' => $contact->tenant_id, 'contact_id' => $contact->id,
        'origin' => \App\Training\Enums\ReminderSuggestionOrigin::UserRequest,
        'proposed_type' => 'training_weekly', 'proposed_params' => ['day' => 'tuesday', 'time' => '19:00', 'recurring' => true],
        'status' => ReminderSuggestionStatus::Pending, 'expires_at' => now()->addHours(24),
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => [], 'training_reply' => null,
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => true,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, 'Sí');

    expect(Reminder::where('contact_id', $contact->id)->where('status', ReminderStatus::Pending)->count())->toBe(1);
    expect(ReminderSuggestion::where('contact_id', $contact->id)->first()->status)->toBe(ReminderSuggestionStatus::Accepted);
});

it('confirming with an override ("Sí, pero a las 8") uses the overridden time, never the original proposal', function () {
    $contact = reminderIntegrationContact();
    ReminderSuggestion::create([
        'tenant_id' => $contact->tenant_id, 'contact_id' => $contact->id,
        'origin' => \App\Training\Enums\ReminderSuggestionOrigin::UserRequest,
        'proposed_type' => 'training_weekly', 'proposed_params' => ['day' => 'tuesday', 'time' => '19:00', 'recurring' => true],
        'status' => ReminderSuggestionStatus::Pending, 'expires_at' => now()->addHours(24),
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => [], 'training_reply' => null,
            'reminder_day' => null, 'reminder_time' => '20:00', 'reminder_recurrence' => null, 'reminder_confirmation' => true,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, 'Sí, pero a las 8');

    $reminder = Reminder::where('contact_id', $contact->id)->first();
    expect($reminder->recurrence['time'])->toBe('20:00');
});

it('proposing a reminder never creates one automatically — requires the separate confirmation turn', function () {
    $contact = reminderIntegrationContact();

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => ['reminder_request'], 'training_reply' => null,
            'reminder_day' => 'monday', 'reminder_time' => '07:00', 'reminder_recurrence' => false, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, 'Recuérdame entrenar el lunes a las 7');

    expect(Reminder::count())->toBe(0);
});

it('cancelling an active Reminder via conversation marks it cancelled', function () {
    $contact = reminderIntegrationContact();
    $reminder = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => ['reminder_cancel'], 'training_reply' => null,
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, 'Ya no quiero ese recordatorio');

    expect($reminder->fresh()->status)->toBe(ReminderStatus::Cancelled);
});

it('a report + a reminder-cancel intent in the same message: session is preserved, cancellation still applies', function () {
    $contact = reminderIntegrationContact();
    [$session, $we] = reminderIntegrationSession($contact);
    $reminder = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null,
            'reports' => [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
                'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
            'session_finished' => false,
            'intents' => ['reminder_cancel'], 'training_reply' => null,
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, 'Hice 10x40 y ya no quiero mi recordatorio');

    expect(\App\Models\ExerciseLog::where('workout_exercise_id', $we->id)->exists())->toBeTrue();
    expect($session->fresh()->id)->toBe($session->id);
    expect($reminder->fresh()->status)->toBe(ReminderStatus::Cancelled);
});

it('a short "sí" within the awaiting_response_until window delivers a session with zero AI calls', function () {
    $contact = reminderIntegrationContact();
    Reminder::factory()->awaitingResponse()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);
    Exercise::factory()->create(['muscle_group' => 'chest']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendReminderIntegrationMessage($contact, 'Sí');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.openai.com'));
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
});

it('a free-form reply within the awaiting window falls through to Coach normally (not the deterministic shortcut)', function () {
    $contact = reminderIntegrationContact();
    Reminder::factory()->awaitingResponse()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => ['general_conversation'], 'training_reply' => 'Está bien, avísame cuando puedas.',
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, 'Hoy no puedo, mejor mañana');

    $openAiCalls = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.openai.com'));
    expect($openAiCalls)->toHaveCount(1);
    expect(WorkoutSession::count())->toBe(0);
});

it('a reply shortly after a fired Reminder routes to Training, not FallbackChatHandler, even without a keyword', function () {
    $contact = reminderIntegrationContact();
    Reminder::factory()->awaitingResponse()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);
    Exercise::factory()->create(['muscle_group' => 'back']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendReminderIntegrationMessage($contact, 'Dale');

    // Si hubiera caído en FallbackChatHandler, nunca se habría generado una
    // WorkoutSession real vía TrainingEngine.
    expect(WorkoutSession::where('contact_id', $contact->id)->exists())->toBeTrue();
});

it('Trigger 2: completing a session offers a proactive reminder when the gate allows it', function () {
    $contact = reminderIntegrationContact();
    [$session, $we] = reminderIntegrationSession($contact);

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null,
            'reports' => [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
                'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
            'session_finished' => true,
            'intents' => [], 'training_reply' => null,
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, 'Hice 10x40, eso fue todo');

    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Completed);
    expect(ReminderSuggestion::where('contact_id', $contact->id)->where('origin', \App\Training\Enums\ReminderSuggestionOrigin::Proactive)->exists())->toBeTrue();
    Http::assertSent(fn ($r) => str_contains(data_get($r->data(), 'text.body', ''), 'recuerde entrenar'));
});
