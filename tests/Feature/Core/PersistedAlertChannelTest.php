<?php

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertSeverity;
use App\Core\Alerts\Channels\PersistedAlertChannel;
use App\Models\AlertLog;

it('supports every alert regardless of category or severity', function () {
    $channel = new PersistedAlertChannel;

    foreach (AlertSeverity::cases() as $severity) {
        expect($channel->supports(new Alert('anything', $severity, 'msg')))->toBeTrue();
    }
});

it('persists the alert as a durable AlertLog row', function () {
    $channel = new PersistedAlertChannel;

    $channel->deliver(new Alert(
        'safety',
        AlertSeverity::Critical,
        'Señal de seguridad detectada',
        ['contact_id' => 5, 'reason' => 'chest_pain'],
    ));

    $log = AlertLog::first();

    expect($log)->not->toBeNull();
    expect($log->category)->toBe('safety');
    expect($log->severity)->toBe('critical');
    expect($log->message)->toBe('Señal de seguridad detectada');
    // MySQL no garantiza preservar el orden de inserción en columnas JSON al
    // leerlas de vuelta — se compara contenido, no orden de claves.
    expect($log->context)->toEqualCanonicalizing(['contact_id' => 5, 'reason' => 'chest_pain']);
    expect($log->delivery_status)->toBe('recorded');
});

it('never persists a context value whose key looks like a secret (redacted by Alert itself)', function () {
    $channel = new PersistedAlertChannel;

    $channel->deliver(new Alert(
        'payments',
        AlertSeverity::Warning,
        'Test',
        ['ai_api_key' => 'sk-should-not-be-stored'],
    ));

    $log = AlertLog::first();

    expect($log->context['ai_api_key'])->toBe('[REDACTED]');
});
