<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Reminder;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Training\Support\ReminderMessageComposer;
use App\Training\Support\TrainingReminderExecutor;
use App\Training\Enums\ReminderStatus;
use Illuminate\Support\Facades\Http;

function trainingReminderExecutor(): TrainingReminderExecutor
{
    return app(TrainingReminderExecutor::class);
}

function readyReminderContact(array $tenantOverrides = []): Contact
{
    $tenant = Tenant::factory()->create(array_merge(['ai_provider' => 'openai'], $tenantOverrides));
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    // Ventana abierta — evita depender de plantillas de WhatsApp en estos tests.
    Conversation::create(['tenant_id' => $tenant->id, 'customer_phone' => $contact->customer_phone, 'last_session_at' => now()->subMinutes(5)]);

    return $contact->fresh();
}

function openAiReminderText(string $text): array
{
    return ['choices' => [['message' => ['content' => $text]]]];
}

it('makes exactly one LLM call per Reminder execution, never more', function () {
    $contact = readyReminderContact();
    $reminder = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    Http::fake([
        'api.openai.com/*' => Http::response(openAiReminderText('💪 ¡Hoy toca entrenar! Responde "sí" y preparo tu rutina.')),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
    ]);

    trainingReminderExecutor()->execute($reminder);

    $openAiCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.openai.com'));
    expect($openAiCalls)->toHaveCount(1);
});

it('sends the AI-composed text as the WhatsApp message content when it is valid', function () {
    $contact = readyReminderContact();
    $reminder = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    Http::fake([
        'api.openai.com/*' => Http::response(openAiReminderText('🔥 Es tu día de entrenamiento. ¿Comenzamos? Respóndeme "sí".')),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
    ]);

    $confirmed = trainingReminderExecutor()->execute($reminder);

    expect($confirmed)->toBeTrue();
    $message = WhatsAppMessage::where('customer_phone', $contact->customer_phone)->first();
    expect($message->content)->toBe('🔥 Es tu día de entrenamiento. ¿Comenzamos? Respóndeme "sí".');
});

it('falls back to the deterministic text when the AI call throws, without blocking the send', function () {
    $contact = readyReminderContact();
    $reminder = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    Http::fake([
        'api.openai.com/*' => Http::response(['error' => 'boom'], 500),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
    ]);

    $confirmed = trainingReminderExecutor()->execute($reminder);

    expect($confirmed)->toBeTrue();
    $message = WhatsAppMessage::where('customer_phone', $contact->customer_phone)->first();
    expect($message->content)->toBe(ReminderMessageComposer::FALLBACK_TEXT);
});

it('falls back when the AI returns an empty or JSON-shaped response instead of plain text', function () {
    $contact = readyReminderContact();
    $reminder = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    Http::fake([
        'api.openai.com/*' => Http::response(openAiReminderText('{"text": "esto no es texto plano"}')),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
    ]);

    trainingReminderExecutor()->execute($reminder);

    $message = WhatsAppMessage::where('customer_phone', $contact->customer_phone)->first();
    expect($message->content)->toBe(ReminderMessageComposer::FALLBACK_TEXT);
});

it('the AI response text never alters the underlying facts (fire_at/status) already decided by code', function () {
    $contact = readyReminderContact();
    $reminder = Reminder::factory()->create([
        'contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id,
        'fire_at' => now(), 'status' => ReminderStatus::Pending,
    ]);
    $originalFireAt = $reminder->fire_at;

    // La IA "intenta" afirmar una fecha distinta — no tiene ningún medio
    // para que eso cambie el dato real, porque nunca escribe en el modelo.
    Http::fake([
        'api.openai.com/*' => Http::response(openAiReminderText('Tu entrenamiento es mañana a las 8 PM, no hoy.')),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
    ]);

    trainingReminderExecutor()->execute($reminder);

    expect($reminder->fresh()->fire_at->equalTo($originalFireAt))->toBeTrue();
});

it('sets awaiting_response_until on confirmed delivery, without ever touching status', function () {
    $contact = readyReminderContact();
    $reminder = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id, 'status' => ReminderStatus::Sending]);

    Http::fake([
        'api.openai.com/*' => Http::response(openAiReminderText('💪 Hoy entrenamos. Responde "sí".')),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
    ]);

    trainingReminderExecutor()->execute($reminder);

    $fresh = $reminder->fresh();
    expect($fresh->awaiting_response_until)->not->toBeNull();
    expect($fresh->awaiting_response_until->isFuture())->toBeTrue();
    // status es responsabilidad exclusiva de SendReminderJob, nunca del executor.
    expect($fresh->status)->toBe(ReminderStatus::Sending);
});

it('never sets awaiting_response_until when the delivery is not confirmed', function () {
    $contact = readyReminderContact();
    $reminder = Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    Http::fake([
        'api.openai.com/*' => Http::response(openAiReminderText('💪 Hoy entrenamos.')),
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500),
    ]);

    $confirmed = trainingReminderExecutor()->execute($reminder);

    expect($confirmed)->toBeFalse();
    expect($reminder->fresh()->awaiting_response_until)->toBeNull();
});
