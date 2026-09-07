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

it('Trigger 1: mentioning forgetting to train (no day/time given) offers a proactive reminder tagged proactive/mentioned_forgetting — never creates a Reminder directly', function () {
    $contact = reminderIntegrationContact();

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => ['mentioned_forgetting'], 'training_reply' => null,
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, 'Siempre se me olvida entrenar');

    $suggestion = ReminderSuggestion::where('contact_id', $contact->id)->first();
    expect($suggestion)->not->toBeNull();
    expect($suggestion->origin)->toBe(\App\Training\Enums\ReminderSuggestionOrigin::Proactive);
    expect($suggestion->trigger_reason)->toBe('mentioned_forgetting');
    expect(Reminder::where('contact_id', $contact->id)->count())->toBe(0);
    Http::assertSent(fn ($r) => str_contains(data_get($r->data(), 'text.body', ''), 'recuerde entrenar'));
});

it('Trigger 1 respects the SAME anti-spam cooldown as the other proactive triggers — never a keyword-only shortcut that bypasses the Gate', function () {
    $contact = reminderIntegrationContact();
    ReminderSuggestion::factory()->proactive('session_completed')->create([
        'contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id,
        'status' => ReminderSuggestionStatus::Accepted, 'created_at' => now()->subHours(10),
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => ['mentioned_forgetting'], 'training_reply' => null,
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, 'Siempre se me olvida entrenar');

    // Sigue existiendo únicamente la sugerencia original (Trigger 2, hace
    // 10h) — el cooldown proactivo de 72h bloquea una segunda oferta sin
    // importar cuál de los 3 triggers la dispare.
    expect(ReminderSuggestion::where('contact_id', $contact->id)->count())->toBe(1);
    expect(ReminderSuggestion::where('contact_id', $contact->id)->first()->trigger_reason)->toBe('session_completed');
});

it('Trigger 3: asking when to train offers a proactive reminder tagged proactive/asked_when_to_train — never creates a Reminder directly', function () {
    $contact = reminderIntegrationContact();

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => ['asked_when_to_train'], 'training_reply' => null,
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, '¿Cuándo debería entrenar?');

    $suggestion = ReminderSuggestion::where('contact_id', $contact->id)->first();
    expect($suggestion)->not->toBeNull();
    expect($suggestion->origin)->toBe(\App\Training\Enums\ReminderSuggestionOrigin::Proactive);
    expect($suggestion->trigger_reason)->toBe('asked_when_to_train');
    expect(Reminder::where('contact_id', $contact->id)->count())->toBe(0);
});

it('Trigger 3 also respects the (longer) post-decline cooldown, same as the other proactive triggers', function () {
    $contact = reminderIntegrationContact();
    $suggestion = ReminderSuggestion::factory()->declined()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);
    $suggestion->forceFill(['updated_at' => now()->subHours(100)])->saveQuietly();

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => ['asked_when_to_train'], 'training_reply' => null,
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, '¿Qué días me conviene entrenar?');

    // Ninguna sugerencia nueva — solo sigue existiendo la declinada original.
    expect(ReminderSuggestion::where('contact_id', $contact->id)->count())->toBe(1);
});

it('Trigger 3 coexists with a normal conversational reply in the same turn — the training_reply and the proactive offer are both sent, neither replaces the other', function () {
    $contact = reminderIntegrationContact();

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => ['general_conversation', 'asked_when_to_train'],
            'training_reply' => 'Según tu plan actual, lo ideal es entrenar martes y viernes.',
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendReminderIntegrationMessage($contact, '¿Cuándo debería entrenar?');

    Http::assertSent(fn ($r) => str_contains(data_get($r->data(), 'text.body', ''), 'martes y viernes'));
    Http::assertSent(fn ($r) => str_contains(data_get($r->data(), 'text.body', ''), 'recuerde entrenar'));
    expect(ReminderSuggestion::where('contact_id', $contact->id)->where('trigger_reason', 'asked_when_to_train')->exists())->toBeTrue();
});

it('coexistence of the 3 triggers: once Trigger 1 already created a pending proactive suggestion, Trigger 3 in a later message does not create a second one', function () {
    $contact = reminderIntegrationContact();

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => ['mentioned_forgetting'], 'training_reply' => null,
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);
    sendReminderIntegrationMessage($contact, 'Siempre se me olvida entrenar');
    expect(ReminderSuggestion::where('contact_id', $contact->id)->count())->toBe(1);

    Http::fake([
        'api.openai.com/*' => Http::response(reminderChatBody([
            'safety_signal_text' => null, 'intents' => ['asked_when_to_train'], 'training_reply' => null,
            'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        ])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);
    sendReminderIntegrationMessage($contact, '¿Cuándo debería entrenar?');

    // Misma protección DB-level (uq_reminder_suggestions_pending_contact) y
    // de aplicación (activePendingFor()) que ya cubre ProposeReminder — los
    // 3 triggers comparten el mismo mecanismo anti-duplicado, nunca uno
    // separado por trigger.
    expect(ReminderSuggestion::where('contact_id', $contact->id)->count())->toBe(1);
    expect(ReminderSuggestion::where('contact_id', $contact->id)->first()->trigger_reason)->toBe('mentioned_forgetting');
});

it('confirming a suggestion originated by Trigger 1/3 (origin=proactive) still creates the Reminder exactly like a user-requested one', function () {
    $contact = reminderIntegrationContact();
    ReminderSuggestion::factory()->proactive('mentioned_forgetting')->create([
        'contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id,
        'proposed_params' => ['day' => 'tuesday', 'time' => '19:00', 'recurring' => true],
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
});

it('a pending suggestion still gets confirmed correctly even after 12 unrelated prior messages pushed the original offer out of the 10-message history window', function () {
    $contact = reminderIntegrationContact();

    for ($i = 1; $i <= 12; $i++) {
        \App\Models\WhatsAppMessage::create([
            'tenant_id' => $contact->tenant_id, 'customer_phone' => $contact->customer_phone,
            'role' => $i % 2 === 0 ? 'assistant' : 'user', 'content' => "charla sin relación {$i}",
        ]);
    }

    ReminderSuggestion::create([
        'tenant_id' => $contact->tenant_id, 'contact_id' => $contact->id,
        'origin' => \App\Training\Enums\ReminderSuggestionOrigin::UserRequest,
        'proposed_type' => 'training_weekly', 'proposed_params' => ['day' => 'tuesday', 'time' => '19:00', 'recurring' => true],
        'status' => ReminderSuggestionStatus::Pending, 'expires_at' => now()->addHours(24),
    ]);

    // La IA (mock) confirma usando el HECHO estructurado pendingReminderSuggestion
    // — nunca el historial, que en este test ya no contiene ningún rastro
    // de la oferta original (D053, corrección post-revisión).
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
