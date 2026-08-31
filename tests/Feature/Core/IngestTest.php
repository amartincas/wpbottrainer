<?php

use App\Core\Messaging\Ingest;
use App\Core\Messaging\IngestedMessage;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Http;

/**
 * Ingest (Hito 2): the only two things that can stop the pipeline before the
 * Router ever sees a message — the bot-active gate and audio transcription —
 * moved out of ProcessWhatsAppMessage unchanged. Ingest has zero knowledge of
 * products/leads/prompts.
 */

it('returns null and saves the inbound message when the bot is disabled for the contact', function () {
    $tenant = Tenant::factory()->create();
    Contact::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_phone' => '573001112233',
        'bot_active' => false,
    ]);

    $ingest = new Ingest();
    $result = $ingest->process($tenant, '573001112233', 'Hola', 'wamid.1', 'text', null);

    expect($result)->toBeNull();

    $saved = WhatsAppMessage::where('tenant_id', $tenant->id)
        ->where('customer_phone', '573001112233')
        ->where('role', 'user')
        ->first();

    expect($saved)->not->toBeNull();
    expect($saved->content)->toBe('Hola');
});

it('returns an IngestedMessage unchanged for a normal text message', function () {
    $tenant = Tenant::factory()->create();

    $ingest = new Ingest();
    $result = $ingest->process($tenant, '573001112233', 'Hola, quiero información', 'wamid.1', 'text', null);

    expect($result)->toBeInstanceOf(IngestedMessage::class);
    expect($result->from)->toBe('573001112233');
    expect($result->messageBody)->toBe('Hola, quiero información');
    expect($result->phoneId)->toBe('wamid.1');
    expect($result->messageType)->toBe('text');
});

it('transcribes an audio message and returns it as normalized text', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        // Media URL lookup
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.com/audio.ogg'], 200),
        // Actual media download
        'cdn.example.com/*' => Http::response('fake-audio-bytes', 200),
        // Whisper transcription
        'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'hola quiero un producto'], 200),
    ]);

    $ingest = new Ingest();
    $result = $ingest->process($tenant, '573001112233', null, 'wamid.1', 'audio', 'media123');

    expect($result)->toBeInstanceOf(IngestedMessage::class);
    expect($result->messageBody)->toContain('hola quiero un producto');
});

it('returns null and sends a specific fallback message when transcription fails', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.com/audio.ogg'], 200),
        'cdn.example.com/*' => Http::response('fake-audio-bytes', 200),
        'api.openai.com/v1/audio/transcriptions' => Http::response('server error', 500),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    $ingest = new Ingest();
    $result = $ingest->process($tenant, '573001112233', null, 'wamid.1', 'audio', 'media123');

    expect($result)->toBeNull();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request->url(), 'messages')
            && str_contains(data_get($request->data(), 'text.body', ''), 'No pude transcribir tu audio');
    });
});

it('returns null when an audio message is missing its media id', function () {
    $tenant = Tenant::factory()->create();

    Http::fake();

    $ingest = new Ingest();
    $result = $ingest->process($tenant, '573001112233', null, 'wamid.1', 'audio', null);

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

/**
 * Hito 7: Whisper es siempre OpenAI, sin importar el proveedor de chat del
 * Tenant. openai_transcription_api_key es un campo independiente de
 * ai_api_key/ai_provider — estos tests confirman que la transcripción nunca
 * usa la key de chat, ni cuando esa key es de otro proveedor (Grok) ni
 * cuando "coincide" con ser también OpenAI.
 */
it('transcribes using openai_transcription_api_key when the tenant chat provider is grok', function () {
    $tenant = Tenant::factory()->create([
        'ai_provider' => 'grok',
        'ai_api_key' => 'xai-this-is-the-grok-chat-key',
        'openai_transcription_api_key' => 'sk-this-is-the-real-openai-whisper-key',
    ]);

    Http::fake([
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.com/audio.ogg'], 200),
        'cdn.example.com/*' => Http::response('fake-audio-bytes', 200),
        'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'hola quiero un producto'], 200),
    ]);

    $ingest = new Ingest();
    $result = $ingest->process($tenant, '573001112233', null, 'wamid.1', 'audio', 'media123');

    expect($result)->toBeInstanceOf(IngestedMessage::class);
    expect($result->messageBody)->toContain('hola quiero un producto');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.openai.com/v1/audio/transcriptions')
            && $request->header('Authorization')[0] === 'Bearer sk-this-is-the-real-openai-whisper-key';
    });
});

it('transcribes using openai_transcription_api_key even when the chat provider is also openai', function () {
    // Distinct on purpose from ai_api_key, aunque ambas sean "openai" — el
    // punto es que nunca deben mezclarse, ni por coincidencia de proveedor.
    $tenant = Tenant::factory()->create([
        'ai_provider' => 'openai',
        'ai_api_key' => 'sk-openai-CHAT-key-never-used-for-whisper',
        'openai_transcription_api_key' => 'sk-openai-WHISPER-key',
    ]);

    Http::fake([
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.com/audio.ogg'], 200),
        'cdn.example.com/*' => Http::response('fake-audio-bytes', 200),
        'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'hola quiero un producto'], 200),
    ]);

    $ingest = new Ingest();
    $result = $ingest->process($tenant, '573001112233', null, 'wamid.1', 'audio', 'media123');

    expect($result)->toBeInstanceOf(IngestedMessage::class);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.openai.com/v1/audio/transcriptions')
            && $request->header('Authorization')[0] === 'Bearer sk-openai-WHISPER-key';
    });
});

it('fails in a controlled, identifiable way and never calls Whisper when openai_transcription_api_key is missing', function () {
    $tenant = Tenant::factory()->create([
        'ai_provider' => 'grok',
        'ai_api_key' => 'xai-grok-chat-key',
        'openai_transcription_api_key' => null,
    ]);

    Http::fake([
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.com/audio.ogg'], 200),
        'cdn.example.com/*' => Http::response('fake-audio-bytes', 200),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    $ingest = new Ingest();
    $result = $ingest->process($tenant, '573001112233', null, 'wamid.1', 'audio', 'media123');

    expect($result)->toBeNull();

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'api.openai.com');
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request->url(), 'messages')
            && str_contains(data_get($request->data(), 'text.body', ''), 'No pude transcribir tu audio');
    });
});
