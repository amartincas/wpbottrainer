<?php

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertSeverity;

/**
 * Alert (Hito 7.1): inmutable, y sanea su propio `context` — ningún canal
 * puede ver un valor cuya clave luce como un secreto, sin importar qué
 * dominio construyó la Alert. Defensa sistémica, no solo disciplina en
 * cada punto de emisión.
 */

it('is immutable — readonly properties cannot be reassigned', function () {
    $alert = new Alert('safety', AlertSeverity::Critical, 'Test');

    expect(fn () => $alert->category = 'other')->toThrow(Error::class);
});

it('redacts context keys that look like secrets, regardless of casing', function () {
    $alert = new Alert('payments', AlertSeverity::Warning, 'Test', [
        'ai_api_key' => 'sk-real-secret-value',
        'wa_access_token' => 'EAGVV-real-token',
        'PASSWORD' => 'hunter2',
        'client_secret' => 'abc',
        'admin_credential' => 'xyz',
        'payment_id' => 23,
        'tenant_id' => 1,
    ]);

    expect($alert->context['ai_api_key'])->toBe('[REDACTED]');
    expect($alert->context['wa_access_token'])->toBe('[REDACTED]');
    expect($alert->context['PASSWORD'])->toBe('[REDACTED]');
    expect($alert->context['client_secret'])->toBe('[REDACTED]');
    expect($alert->context['admin_credential'])->toBe('[REDACTED]');
    // Claves normales, no relacionadas a secretos, pasan intactas.
    expect($alert->context['payment_id'])->toBe(23);
    expect($alert->context['tenant_id'])->toBe(1);
});

it('keeps context empty by default', function () {
    $alert = new Alert('infrastructure', AlertSeverity::Info, 'Test');

    expect($alert->context)->toBe([]);
});
