<?php

namespace App\Core\Messaging;

use Illuminate\Contracts\Container\Container;

/**
 * Tries each registered PreRoutingScreen in order, BEFORE Router ever runs
 * (Hito 7). The first screen that returns true "claims" the message — it
 * already handled it completely, so the normal Router/Dispatcher pipeline is
 * skipped for this message entirely. If none claim it, screening is a no-op
 * and the pipeline continues exactly as before this mechanism existed.
 *
 * Same Router→Container→Classifier resolution pattern as
 * App\Core\Messaging\Router — see docs/DECISIONS.md. This class contains
 * zero domain knowledge: no keyword list, no concept of "safety" or any
 * other vertical lives here.
 */
class PreRoutingScreener
{
    /**
     * @param array<int, class-string<PreRoutingScreenInterface>> $screenClasses
     *        Tried in order; the first one that returns true wins.
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $screenClasses,
    ) {}

    public function screen(ExecutionContext $context): bool
    {
        foreach ($this->screenClasses as $screenClass) {
            /** @var PreRoutingScreenInterface $screen */
            $screen = $this->container->make($screenClass);

            if ($screen->screen($context)) {
                return true;
            }
        }

        return false;
    }
}
