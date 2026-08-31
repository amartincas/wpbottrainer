<?php

namespace App\Core\Memory;

use App\Core\Messaging\ExecutionContext;
use Illuminate\Contracts\Container\Container;

/**
 * Assembles exactly the memory a Handler asks for — nothing more.
 *
 * ContextBuilder → Container → ContextProvider, the same resolution pattern
 * as App\Core\Messaging\Dispatcher: the provider map holds class names, not
 * instances, so a future provider with its own constructor dependencies
 * needs no change here.
 *
 * A Handler decides *which* keys it needs (it knows what its intent is
 * about); ContextBuilder decides nothing about *what those keys mean* — it
 * only knows how to resolve a registered key to a provider and collect the
 * fragments. Providers that are not requested are never instantiated or
 * invoked.
 *
 * No real provider is registered yet (see App\Providers\AppServiceProvider) —
 * this ships as working, tested infrastructure ahead of its first real
 * consumer, the same way the Router shipped in Hito 2 before it had a second
 * intent to classify.
 */
class ContextBuilder
{
    /**
     * @param array<string, class-string<ContextProviderInterface>> $providerClasses Key => Provider FQCN
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $providerClasses,
    ) {}

    /**
     * @param string[] $requestedKeys
     * @return ContextFragment[]
     */
    public function build(ExecutionContext $context, array $requestedKeys): array
    {
        $fragments = [];

        foreach ($requestedKeys as $key) {
            $providerClass = $this->providerClasses[$key] ?? null;

            if ($providerClass === null) {
                throw new \RuntimeException("No context provider registered for key: {$key}");
            }

            /** @var ContextProviderInterface $provider */
            $provider = $this->container->make($providerClass);

            $fragments[] = $provider->provide($context);
        }

        return $fragments;
    }
}
