<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Core\Messaging\IntentClassifierInterface;
use App\Core\Messaging\Router;
use App\Models\Tenant;

/**
 * Router (Hito 2/3/5): must classify without any business logic of its own.
 * Since Hito 5, it tries each registered IntentClassifier in order (same
 * Container-resolved-map pattern as Dispatcher/ContextBuilder) and defaults
 * to fallback_chat if none recognize the message — Router itself never knows
 * what "training" or any other domain vocabulary means.
 *
 * The real App\Training\Support\TrainingIntentClassifier is covered in
 * tests/Feature/Training/, not here — this file only proves the generic
 * mechanism.
 */

class RouterTestAlwaysNullClassifier implements IntentClassifierInterface
{
    public function classify(ExecutionContext $context): ?Intent
    {
        return null;
    }
}

class RouterTestFixedIntentClassifier implements IntentClassifierInterface
{
    public static int $callCount = 0;

    public function classify(ExecutionContext $context): ?Intent
    {
        self::$callCount++;

        return Intent::Training;
    }
}

beforeEach(function () {
    RouterTestFixedIntentClassifier::$callCount = 0;
});

function makeRouterTestContext(?string $body = 'Hola'): ExecutionContext
{
    return new ExecutionContext(
        tenant: Tenant::factory()->create(),
        conversation: null,
        message: new IngestedMessage('573001112233', $body, 'wamid.1', 'text', null),
    );
}

it('defaults to fallback_chat when no classifier is registered', function () {
    $router = new Router(app(), []);

    expect($router->route(makeRouterTestContext()))->toBe(Intent::FallbackChat);
});

it('defaults to fallback_chat when every registered classifier declines', function () {
    $router = new Router(app(), [RouterTestAlwaysNullClassifier::class]);

    foreach (['', 'cualquier cosa', str_repeat('x', 500), null] as $body) {
        expect($router->route(makeRouterTestContext($body)))->toBe(Intent::FallbackChat);
    }
});

it('returns the intent from the first classifier that recognizes the message', function () {
    $router = new Router(app(), [RouterTestFixedIntentClassifier::class]);

    expect($router->route(makeRouterTestContext()))->toBe(Intent::Training);
});

it('tries classifiers in order and stops at the first non-null result', function () {
    $router = new Router(app(), [
        RouterTestAlwaysNullClassifier::class,
        RouterTestFixedIntentClassifier::class,
    ]);

    expect($router->route(makeRouterTestContext()))->toBe(Intent::Training);
    expect(RouterTestFixedIntentClassifier::$callCount)->toBe(1);
});

it('resolves classifiers via the container, not by direct instantiation', function () {
    // Registering by class-string (not instance) is the whole point of the
    // Dispatcher/ContextBuilder/Router pattern — a future classifier with its
    // own constructor dependencies needs no change here.
    $router = new Router(app(), [RouterTestFixedIntentClassifier::class]);

    $router->route(makeRouterTestContext());

    expect(RouterTestFixedIntentClassifier::$callCount)->toBe(1);
});
