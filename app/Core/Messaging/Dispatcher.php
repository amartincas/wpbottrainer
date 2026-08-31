<?php

namespace App\Core\Messaging;

use Illuminate\Contracts\Container\Container;

/**
 * Resolves the Handler registered for an Intent and invokes it.
 *
 * Dispatcher → Container → Handler: the handler map holds class names, not
 * instances, and the actual instance is built by the Laravel container
 * (`$container->make($handlerClass)`) at dispatch time. This means a future
 * Handler can declare its own constructor dependencies without anyone having
 * to change this wiring — the container resolves them the same way it
 * resolves any other class.
 */
class Dispatcher
{
    /**
     * @param array<string, class-string<HandlerInterface>> $handlerClasses Intent value => Handler FQCN
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $handlerClasses,
    ) {}

    public function dispatch(ExecutionContext $context, Intent $intent): void
    {
        $handlerClass = $this->handlerClasses[$intent->value] ?? null;

        if ($handlerClass === null) {
            throw new \RuntimeException("No handler registered for intent: {$intent->value}");
        }

        /** @var HandlerInterface $handler */
        $handler = $this->container->make($handlerClass);

        $handler->handle($context);
    }
}
