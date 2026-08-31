<?php

use App\Core\Messaging\Dispatcher;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\HandlerInterface;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Models\Tenant;

/**
 * Dispatcher (Hito 2/3): resolves the Handler class registered for an Intent
 * via the Laravel Container (Dispatcher → Container → Handler) and forwards
 * the ExecutionContext untouched. It must fail loudly when an intent has no
 * handler registered.
 */

// A disposable test-double Handler, registered only within this file's tests.
class DispatcherTestSpyHandler implements HandlerInterface
{
    public static ?ExecutionContext $receivedContext = null;
    public static int $callCount = 0;

    public function handle(ExecutionContext $context): void
    {
        self::$callCount++;
        self::$receivedContext = $context;
    }
}

beforeEach(function () {
    DispatcherTestSpyHandler::$callCount = 0;
    DispatcherTestSpyHandler::$receivedContext = null;
});

it('resolves the handler class via the container and invokes it with the ExecutionContext', function () {
    $dispatcher = new Dispatcher(app(), [
        Intent::FallbackChat->value => DispatcherTestSpyHandler::class,
    ]);

    $tenant = Tenant::factory()->create();
    $context = new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage('573001112233', 'hola', 'wamid.1', 'text', null),
        legacy: ['product_context' => 42],
    );

    $dispatcher->dispatch($context, Intent::FallbackChat);

    expect(DispatcherTestSpyHandler::$callCount)->toBe(1);
    expect(DispatcherTestSpyHandler::$receivedContext)->toBe($context);
    expect(DispatcherTestSpyHandler::$receivedContext->legacy)->toBe(['product_context' => 42]);
});

it('throws a clear exception when the intent has no handler registered', function () {
    $dispatcher = new Dispatcher(app(), []); // nothing registered

    $tenant = Tenant::factory()->create();
    $context = new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage('573001112233', 'hola', 'wamid.1', 'text', null),
    );

    expect(fn () => $dispatcher->dispatch($context, Intent::FallbackChat))
        ->toThrow(RuntimeException::class, 'No handler registered for intent: fallback_chat');
});
