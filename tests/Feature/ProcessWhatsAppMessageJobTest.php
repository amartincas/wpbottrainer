<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Http;

/**
 * Covers the Job after the Store->Tenant / Lead->Contact rename: the happy
 * path must still save the conversation and create a Contact exactly as it
 * created a Lead before, with no behavioural change.
 */

it('processes an inbound message and creates a Contact when the AI signals completion', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        'api.openai.com/*' => Http::sequence()
            // 1) Main AI response to the customer
            ->push([
                'choices' => [[
                    'message' => ['content' => '¡Gracias por tu compra! [LEAD_COMPLETE]'],
                ]],
            ])
            // 2) Lead/Contact data extraction call
            ->push([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'customer_name' => 'Juan Perez',
                        'delivery_address_or_location' => null,
                        'product_service_name' => null,
                        'preferred_date_time' => null,
                    ])],
                ]],
            ]),
        'graph.facebook.com/*' => Http::response([
            'messages' => [['id' => 'wamid.OUT123']],
        ], 200),
    ]);

    $job = new ProcessWhatsAppMessage(
        $tenant,
        '573001112233',
        'Quiero confirmar mi pedido',
        'wamid.IN123',
        'text',
    );

    // Job::handle() now type-hints its Core pipeline dependencies (Ingest,
    // Router, Dispatcher) — Laravel resolves those automatically when the
    // queue worker invokes a real job, so we go through the container here
    // too instead of calling handle() with zero arguments.
    app()->call([$job, 'handle']);

    expect(WhatsAppMessage::where('tenant_id', $tenant->id)->where('role', 'user')->count())->toBe(1);
    expect(WhatsAppMessage::where('tenant_id', $tenant->id)->where('role', 'assistant')->count())->toBe(1);

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001112233')->first();
    expect($contact)->not->toBeNull();
    expect($contact->customer_name)->toBe('Juan Perez');
});

it('skips AI processing when the bot is disabled for that contact', function () {
    $tenant = Tenant::factory()->create();

    Contact::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_phone' => '573001112233',
        'bot_active' => false,
    ]);

    Http::fake(); // Any AI/WhatsApp call would fail the test via assertNothingSent below.

    $job = new ProcessWhatsAppMessage(
        $tenant,
        '573001112233',
        'Hola, sigues ahi?',
        'wamid.IN456',
        'text',
    );

    // Job::handle() now type-hints its Core pipeline dependencies (Ingest,
    // Router, Dispatcher) — Laravel resolves those automatically when the
    // queue worker invokes a real job, so we go through the container here
    // too instead of calling handle() with zero arguments.
    app()->call([$job, 'handle']);

    Http::assertNothingSent();

    expect(WhatsAppMessage::where('tenant_id', $tenant->id)->where('role', 'user')->count())->toBe(1);
    expect(WhatsAppMessage::where('tenant_id', $tenant->id)->where('role', 'assistant')->count())->toBe(0);
});

it('processes two tenants independently through the Router/Dispatcher pipeline without mixing their data', function () {
    $tenantA = Tenant::factory()->create(['ai_provider' => 'openai']);
    $tenantB = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Hola, ¿en qué puedo ayudarte?']]],
        ], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    $jobA = new ProcessWhatsAppMessage($tenantA, '573000000001', 'Hola', 'wamid.A', 'text');
    $jobB = new ProcessWhatsAppMessage($tenantB, '573000000002', 'Hola', 'wamid.B', 'text');

    app()->call([$jobA, 'handle']);
    app()->call([$jobB, 'handle']);

    expect(WhatsAppMessage::where('tenant_id', $tenantA->id)->count())->toBe(2);
    expect(WhatsAppMessage::where('tenant_id', $tenantB->id)->count())->toBe(2);
    expect(WhatsAppMessage::where('tenant_id', $tenantA->id)->where('customer_phone', '573000000002')->exists())->toBeFalse();
    expect(WhatsAppMessage::where('tenant_id', $tenantB->id)->where('customer_phone', '573000000001')->exists())->toBeFalse();
});

it('keeps the sticky product on the Conversation across turns after the refactor', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $product = Product::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Zapatillas Runner']);
    Product::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Camiseta Deportiva']);

    // Mirrors what WhatsAppController does (firstOrCreate) before dispatching the Job.
    $conversation = Conversation::create([
        'tenant_id' => $tenant->id,
        'customer_phone' => '573001112233',
        'last_session_at' => now(),
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Claro, esas cuestan...']]],
        ], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    // Turn 1: the customer names the product explicitly.
    $job1 = new ProcessWhatsAppMessage($tenant, '573001112233', 'Quiero las Zapatillas Runner', 'wamid.1', 'text');
    app()->call([$job1, 'handle']);

    $conversation->refresh();
    expect($conversation->current_product_id)->toBe($product->id);

    // Turn 2: a generic follow-up with no product name in it — with two
    // products in the catalog, the sticky product must be the one that keeps
    // the context coherent instead of falling back to the whole catalog.
    $job2 = new ProcessWhatsAppMessage($tenant, '573001112233', 'Sí, me interesa', 'wamid.2', 'text');
    app()->call([$job2, 'handle']);

    $conversation->refresh();
    expect($conversation->current_product_id)->toBe($product->id);
});

it('sends the AI response to the customer via the Meta Graph API and tracks its delivery status', function () {
    $tenant = Tenant::factory()->create([
        'ai_provider' => 'openai',
        'wa_phone_number_id' => '999888777',
    ]);

    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'Respuesta de prueba']]]])
            ->push(['choices' => [['message' => ['content' => json_encode([
                'customer_name' => null,
                'delivery_address_or_location' => null,
                'product_service_name' => null,
                'preferred_date_time' => null,
            ])]]]]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT999']]], 200),
    ]);

    $job = new ProcessWhatsAppMessage($tenant, '573001112233', 'Hola', 'wamid.IN', 'text');
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) use ($tenant) {
        return $request->url() === "https://graph.facebook.com/v20.0/{$tenant->wa_phone_number_id}/messages"
            && ($request['to'] ?? null) === '573001112233'
            && ($request['type'] ?? null) === 'text'
            && str_contains($request['text']['body'] ?? '', 'Respuesta de prueba');
    });

    $aiMessage = WhatsAppMessage::where('tenant_id', $tenant->id)->where('role', 'assistant')->first();
    $status = \App\Services\WhatsAppStatusTracker::getStatus($aiMessage->id);

    expect($status)->not->toBeNull();
    expect($status['wamid'])->toBe('wamid.OUT999');
});

it('sends a generic fallback message and re-throws when the AI provider fails', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        'api.openai.com/*' => Http::response('server error', 500),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    $job = new ProcessWhatsAppMessage($tenant, '573001112233', 'Hola', 'wamid.IN', 'text');

    expect(fn () => app()->call([$job, 'handle']))->toThrow(Exception::class);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'graph.facebook.com')
            && str_contains(data_get($request->data(), 'text.body', ''), 'estoy experimentando dificultades técnicas');
    });
});
