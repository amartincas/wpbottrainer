<?php

namespace App\Core\Messaging;

use App\Models\Conversation;
use App\Models\Tenant;

/**
 * The single object that flows through Router → Dispatcher → Handler,
 * replacing the separate (Tenant, IngestedMessage, array $context) parameters
 * used in Hito 2.
 *
 * Deliberately minimal (Hito 3): only what the current pipeline actually
 * needs. No `Contact` (nothing consumes one yet — a future ContextProvider
 * can resolve its own Contact from `tenant` + `message->from` when it
 * genuinely needs one) and no `fragments` (those come from ContextBuilder as
 * a direct return value to whichever Handler asks for them — see
 * App\Core\Memory\ContextBuilder — not as mutable state carried here).
 */
final class ExecutionContext
{
    /**
     * @param array<string, mixed> $legacy Transitional, opaque transport bag
     *        (see docs/DECISIONS.md, D016). Today it carries only
     *        `product_context`, exclusively for App\Handlers\FallbackChatHandler
     *        (the legacy ecommerce flow). Core never reads or writes to it.
     *        No new domain keys should be added here.
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?Conversation $conversation,
        public readonly IngestedMessage $message,
        public readonly array $legacy = [],
    ) {}
}
