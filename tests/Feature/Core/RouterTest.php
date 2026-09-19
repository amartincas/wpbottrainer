<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Core\Messaging\IntentClassifierInterface;
use App\Core\Messaging\Router;
use App\Models\Tenant;

/**
 * Router (Hito 2/3/5, tiers desde la corrección de precedencia de Intents —
 * ver docs/DECISIONS.md): must classify without any business logic of its
 * own. Classifiers se registran agrupados en TIERS ordenados — dentro de un
 * tier, el primero que reconoce el mensaje gana (mismo Container-resolved-map
 * pattern que Dispatcher/ContextBuilder); un tier solo se evalúa si TODO el
 * tier anterior devolvió null. Sin ningún tier con resultado, cae a
 * fallback_chat.
 *
 * La real App\Training\Support\TrainingIntentClassifier (y su contraparte
 * contextual) se cubre en tests/Feature/Training/, no aquí — este archivo
 * solo prueba el mecanismo genérico.
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

class RouterTestAnotherFixedIntentClassifier implements IntentClassifierInterface
{
    public static int $callCount = 0;

    public function classify(ExecutionContext $context): ?Intent
    {
        self::$callCount++;

        return Intent::Payment;
    }
}

beforeEach(function () {
    RouterTestFixedIntentClassifier::$callCount = 0;
    RouterTestAnotherFixedIntentClassifier::$callCount = 0;
});

function makeRouterTestContext(?string $body = 'Hola'): ExecutionContext
{
    return new ExecutionContext(
        tenant: Tenant::factory()->create(),
        conversation: null,
        message: new IngestedMessage('573001112233', $body, 'wamid.1', 'text', null),
    );
}

it('defaults to fallback_chat when no tier is registered', function () {
    $router = new Router(app(), []);

    expect($router->route(makeRouterTestContext()))->toBe(Intent::FallbackChat);
});

it('defaults to fallback_chat when every classifier in every tier declines', function () {
    $router = new Router(app(), [
        [RouterTestAlwaysNullClassifier::class],
        [RouterTestAlwaysNullClassifier::class],
    ]);

    foreach (['', 'cualquier cosa', str_repeat('x', 500), null] as $body) {
        expect($router->route(makeRouterTestContext($body)))->toBe(Intent::FallbackChat);
    }
});

it('returns the intent from the first classifier that recognizes the message', function () {
    $router = new Router(app(), [[RouterTestFixedIntentClassifier::class]]);

    expect($router->route(makeRouterTestContext()))->toBe(Intent::Training);
});

it('tries classifiers within a tier in order and stops at the first non-null result', function () {
    $router = new Router(app(), [[
        RouterTestAlwaysNullClassifier::class,
        RouterTestFixedIntentClassifier::class,
    ]]);

    expect($router->route(makeRouterTestContext()))->toBe(Intent::Training);
    expect(RouterTestFixedIntentClassifier::$callCount)->toBe(1);
});

it('resolves classifiers via the container, not by direct instantiation', function () {
    // Registering by class-string (not instance) is the whole point of the
    // Dispatcher/ContextBuilder/Router pattern — a future classifier with its
    // own constructor dependencies needs no change here.
    $router = new Router(app(), [[RouterTestFixedIntentClassifier::class]]);

    $router->route(makeRouterTestContext());

    expect(RouterTestFixedIntentClassifier::$callCount)->toBe(1);
});

/**
 * Núcleo de la corrección de precedencia (ver docs/DECISIONS.md): un tier
 * completo debe agotarse (todos sus classifiers devuelven null) antes de
 * intentar el siguiente — un classifier de un tier posterior NUNCA se
 * invoca si uno de un tier anterior ya resolvió el Intent.
 */
it('only evaluates a later tier when EVERY classifier in every earlier tier returned null', function () {
    $router = new Router(app(), [
        [RouterTestFixedIntentClassifier::class], // tier 0: resuelve de inmediato
        [RouterTestAnotherFixedIntentClassifier::class], // tier 1: nunca debería llamarse
    ]);

    expect($router->route(makeRouterTestContext()))->toBe(Intent::Training);
    expect(RouterTestFixedIntentClassifier::$callCount)->toBe(1);
    expect(RouterTestAnotherFixedIntentClassifier::$callCount)->toBe(0);
});

it('falls through to a later tier when the entire earlier tier declines', function () {
    $router = new Router(app(), [
        [RouterTestAlwaysNullClassifier::class], // tier 0: null -> el Router debe seguir
        [RouterTestAnotherFixedIntentClassifier::class], // tier 1: gana
    ]);

    expect($router->route(makeRouterTestContext()))->toBe(Intent::Payment);
    expect(RouterTestAnotherFixedIntentClassifier::$callCount)->toBe(1);
});
