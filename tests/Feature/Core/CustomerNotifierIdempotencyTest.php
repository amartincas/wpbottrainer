<?php

use App\Core\Notifications\CustomerNotifier;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Http;

/**
 * Hito 10 — idempotencia real de CustomerNotifier vía
 * WhatsAppMessage.idempotency_key + dispatch_confirmed_at. Garantía:
 * "como máximo una entrega CONFIRMADA por clave lógica" — nunca
 * "exactly once" (ver docs/DECISIONS.md). Escenario central: el proceso
 * pudo morir entre persistOutbound() y la respuesta real de Meta.
 */
function openWindowFor(Tenant $tenant, string $phone): void
{
    Conversation::create(['tenant_id' => $tenant->id, 'customer_phone' => $phone, 'last_session_at' => now()->subHour()]);
}

it('a second notify() with the same idempotency_key already confirmed never sends again', function () {
    $tenant = Tenant::factory()->create();
    openWindowFor($tenant, '573001112233');
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

    $result1 = app(CustomerNotifier::class)->notify($tenant, '573001112233', 'training_reminder', [], 'Hoy toca entrenar', 'reminder:1:occurrence:1000');
    expect($result1->confirmed)->toBeTrue();
    Http::assertSentCount(1);

    $result2 = app(CustomerNotifier::class)->notify($tenant, '573001112233', 'training_reminder', [], 'Hoy toca entrenar (regenerado)', 'reminder:1:occurrence:1000');
    expect($result2->confirmed)->toBeTrue();
    // Ninguna petición NUEVA a Meta — sigue en 1.
    Http::assertSentCount(1);
    expect(WhatsAppMessage::where('idempotency_key', 'reminder:1:occurrence:1000')->count())->toBe(1);
});

it('a retry after an unknown outcome (dispatch_confirmed_at still null) reuses the same row and retries the real send', function () {
    $tenant = Tenant::factory()->create();
    openWindowFor($tenant, '573001112233');

    // Simula que un intento anterior dejó la fila sin confirmar (el proceso
    // murió antes de la respuesta real de Meta) — se crea a mano, sin pasar
    // por notify(), exactamente como quedaría tras ese crash.
    $existing = WhatsAppMessage::create([
        'tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'role' => 'assistant',
        'content' => 'Hoy toca entrenar', 'idempotency_key' => 'reminder:2:occurrence:2000',
        'dispatch_confirmed_at' => null,
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.2']]], 200)]);

    $result = app(CustomerNotifier::class)->notify($tenant, '573001112233', 'training_reminder', [], 'Hoy toca entrenar', 'reminder:2:occurrence:2000');

    expect($result->confirmed)->toBeTrue();
    Http::assertSentCount(1); // sí reintenta el envío real
    expect(WhatsAppMessage::where('idempotency_key', 'reminder:2:occurrence:2000')->count())->toBe(1); // nunca una segunda fila
    expect($existing->fresh()->dispatch_confirmed_at)->not->toBeNull();
});

it('dispatch_confirmed_at stays null when the real send fails, so a future retry is still attempted', function () {
    $tenant = Tenant::factory()->create();
    openWindowFor($tenant, '573001112233');
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

    $result = app(CustomerNotifier::class)->notify($tenant, '573001112233', 'training_reminder', [], 'Hoy toca entrenar', 'reminder:3:occurrence:3000');

    expect($result->confirmed)->toBeFalse();
    $message = WhatsAppMessage::where('idempotency_key', 'reminder:3:occurrence:3000')->first();
    expect($message)->not->toBeNull();
    expect($message->dispatch_confirmed_at)->toBeNull();
});

it('a different occurrence (different idempotency_key) of the same Reminder is a legitimately separate send', function () {
    $tenant = Tenant::factory()->create();
    openWindowFor($tenant, '573001112233');
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.4']]], 200)]);

    app(CustomerNotifier::class)->notify($tenant, '573001112233', 'training_reminder', [], 'Semana 1', 'reminder:4:occurrence:1000');
    app(CustomerNotifier::class)->notify($tenant, '573001112233', 'training_reminder', [], 'Semana 2', 'reminder:4:occurrence:1700');

    Http::assertSentCount(2);
    expect(WhatsAppMessage::whereIn('idempotency_key', ['reminder:4:occurrence:1000', 'reminder:4:occurrence:1700'])->count())->toBe(2);
});

it('notify() without an idempotency_key behaves exactly as before (Payments path unaffected)', function () {
    $tenant = Tenant::factory()->create();
    openWindowFor($tenant, '573001112233');
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.5']]], 200)]);

    app(CustomerNotifier::class)->notify($tenant, '573001112233', 'payment_confirmed', [], 'Tu pago fue confirmado.');

    $message = WhatsAppMessage::where('tenant_id', $tenant->id)->first();
    expect($message->idempotency_key)->toBeNull();
    expect($message->dispatch_confirmed_at)->toBeNull();
});
