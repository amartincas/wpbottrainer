<?php

namespace App\Core\Memory;

use App\Core\Messaging\ExecutionContext;

/**
 * Contract for anything that can contribute one piece of structured memory
 * to a Handler that asks for it via ContextBuilder.
 *
 * A provider decides for itself how to resolve whatever identity it needs
 * (e.g. a Contact, a training profile) from `$context->tenant` and
 * `$context->message->from` — ExecutionContext deliberately does not carry a
 * pre-resolved Contact, so a provider that doesn't need one never pays for
 * looking it up.
 *
 * No real provider exists yet (Hito 3 builds the mechanism only); the first
 * ones arrive with the first real Training Handler.
 */
interface ContextProviderInterface
{
    public function provide(ExecutionContext $context): ContextFragment;
}
