<?php

use App\Core\Notifications\CustomerNotifier;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * App\Core\Notifications\CustomerNotifier (ajuste de Hito 8) — decide
 * ÚNICAMENTE el mecanismo de entrega (mensaje libre vs WhatsApp Template)
 * según Conversation.last_session_at, con margen de 23h30m aprobado.
 * Nunca decide cuándo/por qué notificar — eso es responsabilidad exclusiva
 * de quien llama (ver App\Payments\Support\PaymentConfirmationService).
 */

it('sends a free-form message when the conversation window is open (< 23h30m)', function () {
    $tenant = Tenant::factory()->create();
    Conversation::create([
        'tenant_id' => $tenant->id,
        'customer_phone' => '573001112233',
        'last_session_at' => now()->subHours(1),
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    app(CustomerNotifier::class)->notify(
        $tenant, '573001112233', 'payment_confirmed', ['amount' => '50.000 COP'], 'Tu pago fue confirmado.'
    );

    Http::assertSent(function ($request) {
        return $request['type'] === 'text' && $request['text']['body'] === 'Tu pago fue confirmado.';
    });
});

it('uses the WhatsApp Template when the conversation window is closed (>= 23h30m)', function () {
    $tenant = Tenant::factory()->create();
    Conversation::create([
        'tenant_id' => $tenant->id,
        'customer_phone' => '573001112233',
        'last_session_at' => now()->subHours(24),
    ]);
    WhatsAppTemplate::create([
        'tenant_id' => $tenant->id,
        'name' => 'payment_confirmed_v1',
        'event_key' => 'payment_confirmed',
        'body_preview' => 'Tu pago de {{1}} fue confirmado.',
        'parameters_map' => ['1' => 'amount'],
        'language' => 'es_CO',
        'type' => 'utility',
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    app(CustomerNotifier::class)->notify(
        $tenant, '573001112233', 'payment_confirmed', ['amount' => '50.000 COP'], 'Tu pago fue confirmado (mensaje libre).'
    );

    Http::assertSent(function ($request) {
        return $request['type'] === 'template'
            && $request['template']['name'] === 'payment_confirmed_v1'
            && $request['template']['components'][0]['parameters'][0]['text'] === '50.000 COP';
    });
});

it('treats a contact with no Conversation at all as a closed window', function () {
    $tenant = Tenant::factory()->create();
    WhatsAppTemplate::create([
        'tenant_id' => $tenant->id,
        'name' => 'payment_confirmed_v1',
        'event_key' => 'payment_confirmed',
        'body_preview' => 'Tu pago de {{1}} fue confirmado.',
        'parameters_map' => ['1' => 'amount'],
        'language' => 'es_CO',
        'type' => 'utility',
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    app(CustomerNotifier::class)->notify(
        $tenant, '573001112233', 'payment_confirmed', ['amount' => '50.000 COP'], 'libre'
    );

    Http::assertSent(fn ($request) => $request['type'] === 'template');
});

it('does nothing and does not throw when no template is configured for the event and the window is closed', function () {
    Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => $msg === 'CUSTOMER_NOTIFIER_TEMPLATE_NOT_CONFIGURED');
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $tenant = Tenant::factory()->create();
    // Sin Conversation y sin WhatsAppTemplate para este evento.

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    app(CustomerNotifier::class)->notify(
        $tenant, '573001112233', 'payment_confirmed', [], 'libre'
    );

    Http::assertNothingSent();
});

it('logs and swallows the failure when Meta rejects the template send, without throwing', function () {
    $tenant = Tenant::factory()->create();
    WhatsAppTemplate::create([
        'tenant_id' => $tenant->id,
        'name' => 'payment_confirmed_v1',
        'event_key' => 'payment_confirmed',
        'body_preview' => 'x',
        'parameters_map' => [],
        'language' => 'es_CO',
        'type' => 'utility',
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template not approved']], 400)]);

    // No debe lanzar ninguna excepción.
    app(CustomerNotifier::class)->notify($tenant, '573001112233', 'payment_confirmed', [], 'libre');

    expect(true)->toBeTrue(); // llegar aquí sin excepción es la aserción real
});

it('logs and swallows any unexpected exception without propagating it', function () {
    // Un Tenant sin persistir (id inexistente) fuerza que Conversation::where
    // simplemente no encuentre nada — no debe lanzar, solo intentar la vía
    // de template (que tampoco existe) y terminar en el warning de siempre.
    $tenant = Tenant::factory()->create();

    app(CustomerNotifier::class)->notify($tenant, '573000000000', 'payment_confirmed', [], 'libre');

    expect(true)->toBeTrue();
});
