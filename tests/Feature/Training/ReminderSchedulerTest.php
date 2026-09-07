<?php

use App\Jobs\SendReminderJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Reminder;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Training\Enums\ReminderStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function schedulerReadyContact(): Contact
{
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    Conversation::create(['tenant_id' => $tenant->id, 'customer_phone' => $contact->customer_phone, 'last_session_at' => now()->subMinutes(5)]);

    return $contact->fresh();
}

function fakeReminderAi(): void
{
    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '💪 Hoy toca entrenar.']]]]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
    ]);
}

// ── reminders:dispatch-due ───────────────────────────────────────────────

it('dispatch-due queues one SendReminderJob per due, pending Reminder — never for future or non-pending ones', function () {
    Queue::fake();
    $contact = schedulerReadyContact();

    $due = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id, 'fire_at' => now()->subMinute()]);

    $sendingContact = Contact::factory()->create(['tenant_id' => $contact->tenant_id]);
    Reminder::factory()->create(['contact_id' => $sendingContact->id, 'tenant_id' => $contact->tenant_id, 'fire_at' => now()->addWeek(), 'status' => ReminderStatus::Sending]);

    $futureContact = Contact::factory()->create(['tenant_id' => $contact->tenant_id]);
    Reminder::factory()->create(['contact_id' => $futureContact->id, 'tenant_id' => $contact->tenant_id, 'fire_at' => now()->addDay()]);

    $this->artisan('reminders:dispatch-due')->assertSuccessful();

    Queue::assertPushed(SendReminderJob::class, 1);
    Queue::assertPushed(fn (SendReminderJob $job) => $job->reminderId === $due->id);
});

// ── SendReminderJob ──────────────────────────────────────────────────────

it('claims atomically (pending->sending) and finalizes a one-off Reminder as sent', function () {
    $contact = schedulerReadyContact();
    $reminder = Reminder::factory()->oneOff()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id, 'fire_at' => now()]);
    fakeReminderAi();

    (new SendReminderJob($reminder->id))->handle(app(\App\Core\Reminders\ReminderDispatcher::class));

    $fresh = $reminder->fresh();
    expect($fresh->status)->toBe(ReminderStatus::Sent);
    expect($fresh->last_fired_at)->not->toBeNull();
});

it('finalizes a recurring Reminder back to pending with fire_at advanced by one week', function () {
    $contact = schedulerReadyContact();
    $originalFireAt = now();
    $reminder = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id, 'fire_at' => $originalFireAt]);
    fakeReminderAi();

    (new SendReminderJob($reminder->id))->handle(app(\App\Core\Reminders\ReminderDispatcher::class));

    $fresh = $reminder->fresh();
    expect($fresh->status)->toBe(ReminderStatus::Pending);
    // Comparación a precisión de segundo: la columna `fire_at` (timestamp,
    // sin fracción de segundo) trunca el microsegundo de $originalFireAt al
    // persistir, mientras que $originalFireAt en memoria lo conserva —
    // discrepancia de precisión de la prueba, no del comportamiento real.
    expect($fresh->fire_at->toDateTimeString())->toBe($originalFireAt->addWeek()->toDateTimeString());
    expect($fresh->recovery_attempts)->toBe(0);
});

it('skips silently when the Reminder is no longer pending at claim time (already claimed/cancelled)', function () {
    $contact = schedulerReadyContact();
    $reminder = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id, 'status' => ReminderStatus::Cancelled]);
    fakeReminderAi();

    (new SendReminderJob($reminder->id))->handle(app(\App\Core\Reminders\ReminderDispatcher::class));

    expect($reminder->fresh()->status)->toBe(ReminderStatus::Cancelled);
    Http::assertNothingSent();
});

it('leaves the Reminder in sending (for recovery) when the delivery is not confirmed', function () {
    $contact = schedulerReadyContact();
    $reminder = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id, 'fire_at' => now()]);
    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'x']]]]),
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500),
    ]);

    (new SendReminderJob($reminder->id))->handle(app(\App\Core\Reminders\ReminderDispatcher::class));

    expect($reminder->fresh()->status)->toBe(ReminderStatus::Sending);
});

// ── reminders:recover-stuck ──────────────────────────────────────────────

it('recovers a stuck Reminder back to pending, incrementing recovery_attempts, when under 4 minutes to the threshold', function () {
    $contact = schedulerReadyContact();
    $reminder = Reminder::factory()->stuckSending(15)->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    $this->artisan('reminders:recover-stuck')->assertSuccessful();

    $fresh = $reminder->fresh();
    expect($fresh->status)->toBe(ReminderStatus::Pending);
    expect($fresh->recovery_attempts)->toBe(1);
});

it('never touches a Reminder that has been in sending for less than the stuck threshold', function () {
    $contact = schedulerReadyContact();
    $reminder = Reminder::factory()->stuckSending(2)->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    $this->artisan('reminders:recover-stuck')->assertSuccessful();

    expect($reminder->fresh()->status)->toBe(ReminderStatus::Sending);
});

it('marks a Reminder failed and alerts once recovery_attempts reaches the limit, never retrying indefinitely', function () {
    $contact = schedulerReadyContact();
    $reminder = Reminder::factory()->stuckSending(15)->create([
        'contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id, 'recovery_attempts' => 3,
    ]);

    $this->artisan('reminders:recover-stuck')->assertSuccessful();

    expect($reminder->fresh()->status)->toBe(ReminderStatus::Failed);
});

it('finalizes directly (no re-send, no new AI call) when the WhatsAppMessage for this occurrence was already confirmed', function () {
    $contact = schedulerReadyContact();
    $reminder = Reminder::factory()->stuckSending(15)->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);
    WhatsAppMessage::create([
        'tenant_id' => $contact->tenant_id, 'customer_phone' => $contact->customer_phone, 'role' => 'assistant',
        'content' => 'Hoy toca entrenar', 'idempotency_key' => $reminder->currentOccurrenceIdempotencyKey(),
        'dispatch_confirmed_at' => now(),
    ]);
    Http::fake(); // cualquier llamada real aquí sería un error de diseño

    $this->artisan('reminders:recover-stuck')->assertSuccessful();

    $fresh = $reminder->fresh();
    expect($fresh->status)->toBe(ReminderStatus::Pending); // recurrente por defecto en la factory -> avanza, no queda "sent"
    Http::assertNothingSent();
});
