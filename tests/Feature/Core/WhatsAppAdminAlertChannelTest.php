<?php

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertSeverity;
use App\Core\Alerts\Channels\WhatsAppAdminAlertChannel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Emisor = Tenant del contexto del alert; destinatario = superadmins
 * globales con teléfono (decisión explícita, ver docs/DECISIONS.md).
 */

beforeEach(function () {
    Cache::flush(); // el throttle usa cache "array" — evita fugas entre tests.
});

it('does not support Info severity — only Warning and Critical page a human', function () {
    $channel = new WhatsAppAdminAlertChannel;
    $tenant = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => true, 'phone' => '573000000000']);

    $alert = new Alert('infrastructure', AlertSeverity::Info, 'Test', ['tenant_id' => $tenant->id]);

    expect($channel->supports($alert))->toBeFalse();
});

it('does not support an alert with no resolvable tenant_id', function () {
    $channel = new WhatsAppAdminAlertChannel;
    User::factory()->create(['is_super_admin' => true, 'phone' => '573000000000']);

    expect($channel->supports(new Alert('queue', AlertSeverity::Critical, 'Test')))->toBeFalse();
});

it('does not support an alert when no super-admin has a phone configured', function () {
    $channel = new WhatsAppAdminAlertChannel;
    $tenant = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => true, 'phone' => null]);
    User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => false, 'phone' => '573000000000']);

    $alert = new Alert('safety', AlertSeverity::Critical, 'Test', ['tenant_id' => $tenant->id]);

    expect($channel->supports($alert))->toBeFalse();
});

it('supports a Critical alert with a resolvable tenant and at least one reachable super-admin', function () {
    $channel = new WhatsAppAdminAlertChannel;
    $tenant = Tenant::factory()->create();
    User::factory()->create(['is_super_admin' => true, 'phone' => '573000000000']);

    $alert = new Alert('safety', AlertSeverity::Critical, 'Test', ['tenant_id' => $tenant->id]);

    expect($channel->supports($alert))->toBeTrue();
});

it('sends via WhatsAppService using the ORIGINATING tenant credentials, to every super-admin phone', function () {
    $tenant = Tenant::factory()->create(['name' => 'Acme Fitness', 'wa_phone_number_id' => '111222333']);
    $otherTenant = Tenant::factory()->create(['wa_phone_number_id' => '999999999']);
    User::factory()->create(['is_super_admin' => true, 'phone' => '573001112233']);
    User::factory()->create(['is_super_admin' => true, 'phone' => '573004445566']);
    User::factory()->create(['is_super_admin' => false, 'phone' => '573009998877']); // no debe recibir nada

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    $channel = new WhatsAppAdminAlertChannel;
    $channel->deliver(new Alert(
        'safety',
        AlertSeverity::Critical,
        'Señal de seguridad detectada (chest_pain)',
        ['tenant_id' => $tenant->id],
    ));

    Http::assertSent(function ($request) use ($tenant) {
        return $request->url() === "https://graph.facebook.com/v20.0/{$tenant->wa_phone_number_id}/messages"
            && $request['to'] === '573001112233'
            && str_contains($request['text']['body'], 'Señal de seguridad detectada')
            && str_contains($request['text']['body'], 'Acme Fitness');
    });

    Http::assertSent(fn ($request) => ($request['to'] ?? null) === '573004445566');

    Http::assertNotSent(fn ($request) => ($request['to'] ?? null) === '573009998877');

    // Nunca debe usar las credenciales del otro tenant.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), $otherTenant->wa_phone_number_id));
});

it('throttles a second identical alert sent within the throttle window', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->create(['is_super_admin' => true, 'phone' => '573001112233']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    $channel = new WhatsAppAdminAlertChannel;
    $alert = new Alert('safety', AlertSeverity::Critical, 'Repeated alert', ['tenant_id' => $tenant->id]);

    $channel->deliver($alert);
    $channel->deliver($alert);

    Http::assertSentCount(1);
});

it('does not throw even when the Meta send fails', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->create(['is_super_admin' => true, 'phone' => '573001112233']);

    Http::fake(['graph.facebook.com/*' => Http::response('server error', 500)]);

    $channel = new WhatsAppAdminAlertChannel;

    $channel->deliver(new Alert('safety', AlertSeverity::Critical, 'Test', ['tenant_id' => $tenant->id]));
})->throwsNoExceptions();
