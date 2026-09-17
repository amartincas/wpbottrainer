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
 *
 * P1-B — `$referral` is the exception that proves the rule, not a
 * violation of it: it is raw, unparsed transport metadata Meta attaches to
 * the message envelope itself (exactly like `$phoneId`/`$mediaId` already
 * are), never a domain concept already resolved by business logic (that is
 * what `$productContext`/`ExecutionContext->legacy` is for, and remains
 * untouched). Core never interprets it — see
 * App\Acquisition\Support\AcquisitionSourcePreRoutingScreen, the only
 * consumer.
 */
final class IngestedMessage
{
    public function __construct(
        public readonly string $from,
        public readonly ?string $messageBody,
        public readonly ?string $phoneId,
        public readonly ?string $messageType,
        public readonly ?string $mediaId,
        public readonly ?array $referral = null,
    ) {}
}
