<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Controles P0 de lanzamiento — rate limit por tenant+contacto
 * (WhatsAppController::handle(), clave "wa-contact:{tenant_id}:{fromPhone}").
 * Esta es la capa que protege el costo real de IA — el throttle por IP de
 * la ruta ("whatsapp-webhook-ip", ver AppServiceProvider::boot()) es una
 * capa aparte, más genérica, no cubierta aquí en detalle.
 */
function rateLimitWebhookPayload(string $wamid, string $from, string $phoneNumberId, string $body = 'hola'): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => $phoneNumberId],
                    'messages' => [[
                        'id' => $wamid,
                        'from' => $from,
                        'type' => 'text',
                        'text' => ['body' => $body],
                    ]],
                ],
            ]],
        ]],
    ];
}

beforeEach(function () {
    config([
        'services.meta.contact_rate_limit_max' => 3,
        'services.meta.contact_rate_limit_minutes' => 10,
    ]);
});

it('allows exactly N messages within the limit', function () {
    Queue::fake();
    Tenant::factory()->create(['wa_phone_number_id' => '1111111111']);

    for ($i = 1; $i <= 3; $i++) {
        $response = $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload("wamid.RL{$i}", '573001112233', '1111111111'));
        $response->assertOk();
    }

    Queue::assertPushed(ProcessWhatsAppMessage::class, 3);
});

it('rejects the N+1th message with 429', function () {
    Queue::fake();
    Tenant::factory()->create(['wa_phone_number_id' => '1111111111']);

    for ($i = 1; $i <= 3; $i++) {
        $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload("wamid.RL{$i}", '573001112233', '1111111111'))->assertOk();
    }

    $response = $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.RL4', '573001112233', '1111111111'));

    $response->assertStatus(429);
    Queue::assertPushed(ProcessWhatsAppMessage::class, 3);
});

it('the exact limit is allowed and the immediately following message is rejected', function () {
    Queue::fake();
    Tenant::factory()->create(['wa_phone_number_id' => '1111111111']);

    $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.EXACT1', '573001112233', '1111111111'))->assertOk();
    $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.EXACT2', '573001112233', '1111111111'))->assertOk();
    $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.EXACT3', '573001112233', '1111111111'))->assertOk(); // el 3º = el límite, permitido

    $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.EXACT4', '573001112233', '1111111111'))->assertStatus(429);
});

it('two different tenants are independent rate-limit buckets, even with the same phone number', function () {
    Queue::fake();
    $tenantA = Tenant::factory()->create(['wa_phone_number_id' => '1111111111']);
    $tenantB = Tenant::factory()->create(['wa_phone_number_id' => '2222222222']);
    $sharedPhone = '573001112233';

    for ($i = 1; $i <= 3; $i++) {
        $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload("wamid.TA{$i}", $sharedPhone, '1111111111'))->assertOk();
    }
    // Tenant A ya agotó su límite — el 4º de A se rechaza.
    $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.TA4', $sharedPhone, '1111111111'))->assertStatus(429);

    // Tenant B, mismo número de teléfono, balde totalmente independiente.
    $response = $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.TB1', $sharedPhone, '2222222222'));
    $response->assertOk();

    Queue::assertPushed(ProcessWhatsAppMessage::class, fn ($job) => $job->tenant->is($tenantA), 3);
    Queue::assertPushed(ProcessWhatsAppMessage::class, fn ($job) => $job->tenant->is($tenantB), 1);
});

it('two different contacts of the same tenant are independent rate-limit buckets', function () {
    Queue::fake();
    Tenant::factory()->create(['wa_phone_number_id' => '1111111111']);

    for ($i = 1; $i <= 3; $i++) {
        $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload("wamid.C1-{$i}", '573001110001', '1111111111'))->assertOk();
    }
    $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.C1-4', '573001110001', '1111111111'))->assertStatus(429);

    // Contacto distinto, mismo tenant — balde independiente, sin heredar el límite ya agotado.
    $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.C2-1', '573001110002', '1111111111'))->assertOk();
});

it('a rate-limited message never marks its WAMID as processed', function () {
    Queue::fake();
    Tenant::factory()->create(['wa_phone_number_id' => '1111111111']);

    for ($i = 1; $i <= 3; $i++) {
        $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload("wamid.MARK{$i}", '573001112233', '1111111111'))->assertOk();
    }

    $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.MARK4', '573001112233', '1111111111'))->assertStatus(429);

    expect(Cache::has('whatsapp_msg_processed:wamid.MARK4'))->toBeFalse();
});

it('the same WAMID, retried after the rate-limit window expires, can be processed', function () {
    Queue::fake();
    Tenant::factory()->create(['wa_phone_number_id' => '1111111111']);

    for ($i = 1; $i <= 3; $i++) {
        $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload("wamid.EXP{$i}", '573001112233', '1111111111'))->assertOk();
    }

    // 4º intento con un WAMID nuevo: rechazado por rate limit, nunca marcado.
    $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.EXP-RETRY', '573001112233', '1111111111'))->assertStatus(429);
    expect(Cache::has('whatsapp_msg_processed:wamid.EXP-RETRY'))->toBeFalse();

    // Meta reintenta la MISMA entrega (mismo WAMID) más tarde, cuando la
    // ventana de rate limit ya expiró — debe poder procesarse.
    $this->travel(11)->minutes();

    $response = $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.EXP-RETRY', '573001112233', '1111111111'));

    $response->assertOk();
    Queue::assertPushed(ProcessWhatsAppMessage::class, fn ($job) => $job->phoneId === 'wamid.EXP-RETRY');
});

it('normal traffic under the limit is never blocked (no regression)', function () {
    Queue::fake();
    Tenant::factory()->create(['wa_phone_number_id' => '1111111111']);

    $response = $this->postJson('/api/whatsapp/webhook/anything', rateLimitWebhookPayload('wamid.NORMAL1', '573001112233', '1111111111'));

    $response->assertOk();
    Queue::assertPushed(ProcessWhatsAppMessage::class, 1);
});

it('repeated sequential hits produce a gapless, monotonically increasing count (no lost updates)', function () {
    // No es una prueba de concurrencia real multi-proceso (no disponible en
    // esta infraestructura de tests estándar) — prueba que el mecanismo
    // subyacente (RateLimiter::hit() sobre el store 'database', atómico via
    // lockForUpdate(), verificado en vendor/laravel/framework antes de
    // implementar) nunca pierde ni duplica un incremento bajo múltiples
    // llamadas consecutivas a la misma clave.
    $key = 'wa-contact:999:573000000000';

    $hits = [];
    for ($i = 0; $i < 5; $i++) {
        $hits[] = \Illuminate\Support\Facades\RateLimiter::hit($key, 600);
    }

    expect($hits)->toBe([1, 2, 3, 4, 5]);
});
