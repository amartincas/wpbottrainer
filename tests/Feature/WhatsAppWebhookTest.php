<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Covers the part of Hito 1 that must keep working exactly as before:
 * Tenant resolution by wa_phone_number_id, and webhook idempotency.
 */

it('resolves the tenant via wa_verify_token on the GET verification challenge', function () {
    // The webhook URL configured in Meta uses the tenant's own wa_verify_token
    // as the path segment (see TenantWizardForm::webhook_url) — the controller
    // looks up the tenant by that path segment, then double-checks it against
    // hub_verify_token before echoing the challenge back.
    $tenant = Tenant::factory()->create([
        'wa_verify_token' => 'my-verify-token',
    ]);

    $response = $this->get('/api/whatsapp/webhook/my-verify-token?' . http_build_query([
        'hub_mode' => 'subscribe',
        'hub_challenge' => '12345',
        'hub_verify_token' => 'my-verify-token',
    ]));

    $response->assertOk();
    $response->assertSee('12345');
});

it('rejects the GET verification challenge when the token does not match any tenant', function () {
    Tenant::factory()->create(['wa_verify_token' => 'correct-token']);

    $response = $this->get('/api/whatsapp/webhook/wrong-token?' . http_build_query([
        'hub_mode' => 'subscribe',
        'hub_challenge' => '12345',
        'hub_verify_token' => 'wrong-token',
    ]));

    $response->assertNotFound();
});

it('resolves the tenant via wa_phone_number_id and dispatches the job on an incoming POST message', function () {
    Queue::fake();

    $tenant = Tenant::factory()->create([
        'wa_phone_number_id' => '1234567890',
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => '1234567890'],
                    'messages' => [[
                        'id' => 'wamid.TEST123',
                        'from' => '573001112233',
                        'type' => 'text',
                        'text' => ['body' => 'Hola, quiero informacion'],
                    ]],
                ],
            ]],
        ]],
    ];

    $response = $this->postJson('/api/whatsapp/webhook/anything', $payload);

    $response->assertOk();

    Queue::assertPushed(ProcessWhatsAppMessage::class, function (ProcessWhatsAppMessage $job) use ($tenant) {
        return $job->tenant->is($tenant) && $job->from === '573001112233';
    });
});

it('ignores the webhook payload when no tenant matches the phone_number_id', function () {
    Queue::fake();

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => 'does-not-exist'],
                    'messages' => [[
                        'id' => 'wamid.TEST999',
                        'from' => '573001112233',
                        'type' => 'text',
                        'text' => ['body' => 'Hola'],
                    ]],
                ],
            ]],
        ]],
    ];

    $response = $this->postJson('/api/whatsapp/webhook/anything', $payload);

    $response->assertNotFound();
    Queue::assertNothingPushed();
});

it('does not dispatch the job twice for a retried WAMID (idempotency)', function () {
    Queue::fake();
    Cache::flush();

    $tenant = Tenant::factory()->create([
        'wa_phone_number_id' => '1234567890',
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => '1234567890'],
                    'messages' => [[
                        'id' => 'wamid.DUPLICATE',
                        'from' => '573001112233',
                        'type' => 'text',
                        'text' => ['body' => 'Hola'],
                    ]],
                ],
            ]],
        ]],
    ];

    // Meta retries the same webhook delivery.
    $this->postJson('/api/whatsapp/webhook/anything', $payload)->assertOk();
    $this->postJson('/api/whatsapp/webhook/anything', $payload)->assertOk();

    Queue::assertPushed(ProcessWhatsAppMessage::class, 1);
});
