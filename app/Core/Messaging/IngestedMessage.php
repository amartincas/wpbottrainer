<?php

namespace App\Core\Messaging;

/**
 * A normalized inbound WhatsApp message, ready for the Router to classify.
 *
 * This is a pure Core-level value object: it only carries generic messaging
 * data (who sent it, what it says, what kind of message it was). It must
 * never carry domain-specific fields (e.g. a product reference) — those stay
 * out of Core and are threaded through Handlers via the opaque `$context`
 * array on Dispatcher/HandlerInterface instead. See docs/DECISIONS.md (D016).
 */
final class IngestedMessage
{
    public function __construct(
        public readonly string $from,
        public readonly ?string $messageBody,
        public readonly ?string $phoneId,
        public readonly ?string $messageType,
        public readonly ?string $mediaId,
    ) {}
}
