<?php

use App\Models\Tenant;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Http;

/**
 * Mejora UX de invitación de Referral (ver docs/DECISIONS.md) —
 * `sendCtaUrlMessage()` es un mensaje interactivo estándar de la Cloud API
 * (`type: "interactive"`, `interactive.type: "cta_url"`), NUNCA un
 * WhatsApp Template — no requiere aprobación de Meta y reutiliza
 * exactamente las mismas credenciales/endpoint/manejo de errores que
 * sendMessage()/sendTemplateMessage(). Este test fija la ESTRUCTURA exacta
 * del payload para que una futura modificación no la rompa sin que un test
 * lo detecte.
 */
it('sends the exact interactive cta_url payload structure Meta expects', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    $tenant = Tenant::factory()->create([
        'wa_phone_number_id' => '1234567890',
        'wa_access_token' => 'fake-token-for-test',
    ]);

    $wamid = WhatsAppService::sendCtaUrlMessage(
        '573001112233',
        'Cuerpo del mensaje',
        'Invitar a un amigo',
        'https://wa.me/573009998877?text=Hola',
        $tenant,
    );

    expect($wamid)->toBe('wamid.OUT');

    Http::assertSent(function ($request) use ($tenant) {
        $data = $request->data();

        return $request->url() === "https://graph.facebook.com/v20.0/{$tenant->wa_phone_number_id}/messages"
            && $request->hasHeader('Authorization', 'Bearer '.$tenant->wa_access_token)
            && ($data['messaging_product'] ?? null) === 'whatsapp'
            && ($data['recipient_type'] ?? null) === 'individual'
            && ($data['to'] ?? null) === '573001112233'
            && ($data['type'] ?? null) === 'interactive'
            && data_get($data, 'interactive.type') === 'cta_url'
            && data_get($data, 'interactive.body.text') === 'Cuerpo del mensaje'
            && data_get($data, 'interactive.action.name') === 'cta_url'
            && data_get($data, 'interactive.action.parameters.display_text') === 'Invitar a un amigo'
            && data_get($data, 'interactive.action.parameters.url') === 'https://wa.me/573009998877?text=Hola'
            // Nunca debe existir una clave text.body — no es un mensaje de texto plano.
            && ! array_key_exists('text', $data);
    });
});

it('returns null and never throws when Meta rejects the CTA URL message', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid parameter']], 400)]);

    $tenant = Tenant::factory()->create();

    $wamid = WhatsAppService::sendCtaUrlMessage('573001112233', 'body', 'Invitar a un amigo', 'https://wa.me/123', $tenant);

    expect($wamid)->toBeNull();
});
