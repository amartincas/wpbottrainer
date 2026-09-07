<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Reminder;
use App\Models\Tenant;
use App\Training\Support\TimezoneResolver;
use App\Training\Support\TrainingReminderExecutor;
use Illuminate\Support\Facades\Http;

/**
 * Hito 10 — un Reminder de un Tenant nunca puede leer datos de otro Tenant:
 * ni su timezone, ni su contacto, ni sus credenciales de WhatsApp.
 */
it('resolves the timezone from the Reminder\'s own Tenant, never from a different one', function () {
    $tenantA = Tenant::factory()->create(['timezone' => 'America/Bogota']);
    $tenantB = Tenant::factory()->create(['timezone' => 'Europe/Madrid']);
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);

    $resolved = (new TimezoneResolver)->resolve($contactA);

    expect($resolved)->toBe('America/Bogota');
    expect($resolved)->not->toBe($tenantB->timezone);
});

it('a Reminder execution sends via its own Tenant\'s WhatsApp credentials, never another Tenant\'s', function () {
    $tenantA = Tenant::factory()->create(['wa_access_token' => 'token-A', 'wa_phone_number_id' => '1111111111']);
    $tenantB = Tenant::factory()->create(['wa_access_token' => 'token-B', 'wa_phone_number_id' => '2222222222']);
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id, 'customer_phone' => '573001110000']);
    Contact::factory()->create(['tenant_id' => $tenantB->id, 'customer_phone' => '573002220000']);

    // Abre la ventana de 24h de CustomerNotifier (mensaje libre en vez de
    // plantilla) — sin esto, sin WhatsAppTemplate configurado, no se envía
    // nada (comportamiento correcto y ya cubierto por otro test).
    Conversation::create([
        'tenant_id' => $tenantA->id,
        'customer_phone' => $contactA->customer_phone,
        'last_session_at' => now(),
    ]);

    $reminder = Reminder::factory()->create(['contact_id' => $contactA->id, 'tenant_id' => $tenantA->id]);

    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Hoy toca entrenar.']]]]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
    ]);

    app(TrainingReminderExecutor::class)->execute($reminder);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '1111111111')
            && $request->hasHeader('Authorization', 'Bearer token-A');
    });
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '2222222222'));
});

it('Reminder::activeFor() and ReminderSuggestion::activePendingFor() never cross tenant/contact boundaries', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    Reminder::factory()->create(['contact_id' => $contactB->id, 'tenant_id' => $tenantB->id]);
    \App\Models\ReminderSuggestion::factory()->create(['contact_id' => $contactB->id, 'tenant_id' => $tenantB->id]);

    expect(Reminder::activeFor($contactA))->toBeNull();
    expect(\App\Models\ReminderSuggestion::activePendingFor($contactA))->toBeNull();
});

it('the unique active-Reminder-per-contact constraint never blocks the same contact_id belonging to a different tenant scenario (contact_id is globally unique regardless)', function () {
    // contact_id ya es globalmente único (una fila real de contacts), así
    // que esto documenta que el índice único opera sobre esa identidad real
    // — dos contactos de tenants distintos SIEMPRE pueden tener cada uno su
    // propio Reminder activo, sin colisión entre sí.
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    Reminder::factory()->create(['contact_id' => $contactA->id, 'tenant_id' => $tenantA->id]);
    Reminder::factory()->create(['contact_id' => $contactB->id, 'tenant_id' => $tenantB->id]);

    expect(Reminder::where('status', \App\Training\Enums\ReminderStatus::Pending)->count())->toBe(2);
});
