<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Tenant;
use App\Models\TrainingProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Controles P0 de lanzamiento — verificación de X-Hub-Signature-256
 * (App\Http\Middleware\VerifyMetaWebhookSignature). La firma se calcula
 * SIEMPRE sobre el cuerpo crudo (mismo que envía App\Illuminate\Foundation\
 * Testing\Concerns\MakesHttpRequests::json(): json_encode($data)) — nunca
 * sobre un array reconstruido, para que el test refleje exactamente cómo
 * Meta firma sus entregas reales.
 */
function signedWebhookPayload(): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => '1234567890'],
                    'messages' => [[
                        'id' => 'wamid.SIGTEST1',
                        'from' => '573001112233',
                        'type' => 'text',
                        'text' => ['body' => 'Hola, quiero informacion'],
                    ]],
                ],
            ]],
        ]],
    ];
}

function signatureHeaderFor(array $payload, string $secret): string
{
    return 'sha256=' . hash_hmac('sha256', json_encode($payload), $secret);
}

it('strict + valid signature processes the webhook normally', function () {
    config(['services.meta.app_secret' => 'test-secret', 'services.meta.webhook_signature_mode' => 'strict']);
    Queue::fake();
    $tenant = Tenant::factory()->create(['wa_phone_number_id' => '1234567890']);
    $payload = signedWebhookPayload();

    $response = $this->postJson('/api/whatsapp/webhook/anything', $payload, [
        'X-Hub-Signature-256' => signatureHeaderFor($payload, 'test-secret'),
    ]);

    $response->assertOk();
    Queue::assertPushed(ProcessWhatsAppMessage::class, fn ($job) => $job->tenant->is($tenant));
});

it('strict + invalid signature returns 401', function () {
    config(['services.meta.app_secret' => 'test-secret', 'services.meta.webhook_signature_mode' => 'strict']);
    Queue::fake();
    Tenant::factory()->create(['wa_phone_number_id' => '1234567890']);
    $payload = signedWebhookPayload();

    $response = $this->postJson('/api/whatsapp/webhook/anything', $payload, [
        'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64),
    ]);

    $response->assertStatus(401);
});

it('strict + missing signature returns 401', function () {
    config(['services.meta.app_secret' => 'test-secret', 'services.meta.webhook_signature_mode' => 'strict']);
    Queue::fake();
    Tenant::factory()->create(['wa_phone_number_id' => '1234567890']);

    $response = $this->postJson('/api/whatsapp/webhook/anything', signedWebhookPayload());

    $response->assertStatus(401);
});

it('strict + payload altered after generating the signature returns 401', function () {
    config(['services.meta.app_secret' => 'test-secret', 'services.meta.webhook_signature_mode' => 'strict']);
    Queue::fake();
    Tenant::factory()->create(['wa_phone_number_id' => '1234567890']);

    $originalPayload = signedWebhookPayload();
    $validSignatureForOriginal = signatureHeaderFor($originalPayload, 'test-secret');

    $tamperedPayload = $originalPayload;
    $tamperedPayload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'mensaje alterado';

    $response = $this->postJson('/api/whatsapp/webhook/anything', $tamperedPayload, [
        'X-Hub-Signature-256' => $validSignatureForOriginal,
    ]);

    $response->assertStatus(401);
});

it('strict + invalid signature never dispatches ProcessWhatsAppMessage', function () {
    config(['services.meta.app_secret' => 'test-secret', 'services.meta.webhook_signature_mode' => 'strict']);
    Queue::fake();
    Tenant::factory()->create(['wa_phone_number_id' => '1234567890']);

    $this->postJson('/api/whatsapp/webhook/anything', signedWebhookPayload(), [
        'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64),
    ]);

    Queue::assertNothingPushed();
});

it('strict + invalid signature never triggers any AI call nor touches TrainingProfile', function () {
    config(['services.meta.app_secret' => 'test-secret', 'services.meta.webhook_signature_mode' => 'strict']);
    Http::fake();
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'wa_phone_number_id' => '1234567890']);

    $this->postJson('/api/whatsapp/webhook/anything', signedWebhookPayload(), [
        'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64),
    ]);

    Http::assertNothingSent();
    expect(TrainingProfile::count())->toBe(0);
});

it('log-only + invalid signature still processes the request and logs a warning', function () {
    config(['services.meta.app_secret' => 'test-secret', 'services.meta.webhook_signature_mode' => 'log-only']);
    Queue::fake();
    Log::spy();
    $tenant = Tenant::factory()->create(['wa_phone_number_id' => '1234567890']);
    $payload = signedWebhookPayload();

    $response = $this->postJson('/api/whatsapp/webhook/anything', $payload, [
        'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64),
    ]);

    $response->assertOk();
    Queue::assertPushed(ProcessWhatsAppMessage::class, fn ($job) => $job->tenant->is($tenant));
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($message, $context) => $message === 'META_WEBHOOK_SIGNATURE_INVALID'
            && $context['mode'] === 'log-only'
            && $context['has_header'] === true
            && $context['has_secret_configured'] === true);
});

it('GET verification challenge never requires a signature, in any mode', function () {
    config(['services.meta.app_secret' => 'test-secret', 'services.meta.webhook_signature_mode' => 'strict']);
    $tenant = Tenant::factory()->create(['wa_verify_token' => 'my-verify-token']);

    $response = $this->get('/api/whatsapp/webhook/my-verify-token?' . http_build_query([
        'hub_mode' => 'subscribe',
        'hub_challenge' => '12345',
        'hub_verify_token' => 'my-verify-token',
    ]));

    $response->assertOk();
    $response->assertSee('12345');
});
