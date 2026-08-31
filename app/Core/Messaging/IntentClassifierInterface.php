<?php

namespace App\Core\Messaging;

/**
 * Contract for a single classification rule Router can try.
 *
 * A classifier decides, from the ExecutionContext alone, whether the message
 * belongs to the Intent it owns — returning null means "not mine", so Router
 * moves on to the next registered classifier (or defaults to
 * Intent::FallbackChat if none match). This is the same
 * Container-resolved-map pattern already used by Dispatcher (Handler
 * classes) and ContextBuilder (ContextProvider classes) — see
 * docs/DECISIONS.md.
 *
 * Implementations live in Domain (e.g. App\Training\Support\TrainingIntentClassifier),
 * never in Core — Core only knows this interface exists, never what any
 * concrete classifier looks for.
 */
interface IntentClassifierInterface
{
    public function classify(ExecutionContext $context): ?Intent;
}
