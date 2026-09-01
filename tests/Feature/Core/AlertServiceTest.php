<?php

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertChannelInterface;
use App\Core\Alerts\AlertSeverity;
use App\Core\Alerts\AlertService;

/**
 * AlertService (Hito 7.1): fan-out a TODOS los canales que soporten la
 * Alert (a diferencia de Router/PreRoutingScreener, que se detienen en el
 * primer resultado) — y garantiza que un canal roto nunca se propaga al
 * llamador. Este archivo solo prueba el mecanismo genérico, sin dominio —
 * los canales reales (PersistedAlertChannel, WhatsAppAdminAlertChannel)
 * tienen sus propios tests.
 */

class AlertServiceTestRecordingChannel implements AlertChannelInterface
{
    public static array $delivered = [];

    public function __construct(private bool $matches = true) {}

    public function supports(Alert $alert): bool
    {
        return $this->matches;
    }

    public function deliver(Alert $alert): void
    {
        self::$delivered[] = $alert->category;
    }
}

class AlertServiceTestAlwaysSupportsChannelA extends AlertServiceTestRecordingChannel
{
    public function __construct() { parent::__construct(true); }
}

class AlertServiceTestAlwaysSupportsChannelB extends AlertServiceTestRecordingChannel
{
    public function __construct() { parent::__construct(true); }
}

class AlertServiceTestNeverSupportsChannel implements AlertChannelInterface
{
    public function supports(Alert $alert): bool
    {
        return false;
    }

    public function deliver(Alert $alert): void
    {
        throw new \RuntimeException('deliver() must never be called when supports() is false');
    }
}

class AlertServiceTestThrowingChannel implements AlertChannelInterface
{
    public function supports(Alert $alert): bool
    {
        return true;
    }

    public function deliver(Alert $alert): void
    {
        throw new \RuntimeException('simulated channel failure');
    }
}

beforeEach(function () {
    AlertServiceTestRecordingChannel::$delivered = [];
});

function makeTestAlert(string $category = 'safety'): Alert
{
    return new Alert($category, AlertSeverity::Critical, 'Test message');
}

it('delivers to every registered channel that supports the alert (fan-out, not first-match)', function () {
    $service = new AlertService(app(), [
        AlertServiceTestAlwaysSupportsChannelA::class,
        AlertServiceTestAlwaysSupportsChannelB::class,
    ]);

    $service->send(makeTestAlert('payments'));

    expect(AlertServiceTestRecordingChannel::$delivered)->toBe(['payments', 'payments']);
});

it('never calls deliver() on a channel whose supports() returns false', function () {
    $service = new AlertService(app(), [AlertServiceTestNeverSupportsChannel::class]);

    $service->send(makeTestAlert());
})->throwsNoExceptions();

it('isolates a failing channel — it does not stop other channels from receiving the alert', function () {
    $service = new AlertService(app(), [
        AlertServiceTestThrowingChannel::class,
        AlertServiceTestAlwaysSupportsChannelA::class,
    ]);

    $service->send(makeTestAlert('infrastructure'));

    expect(AlertServiceTestRecordingChannel::$delivered)->toBe(['infrastructure']);
});

it('never lets a channel failure propagate to the caller', function () {
    $service = new AlertService(app(), [AlertServiceTestThrowingChannel::class]);

    $service->send(makeTestAlert());
})->throwsNoExceptions();

it('does nothing when no channel is registered', function () {
    $service = new AlertService(app(), []);

    $service->send(makeTestAlert());
})->throwsNoExceptions();
