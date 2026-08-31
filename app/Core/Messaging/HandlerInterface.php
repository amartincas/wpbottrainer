<?php

namespace App\Core\Messaging;

/**
 * Contract every intent Handler must implement.
 *
 * Handlers are resolved and invoked by the Dispatcher; they are independent
 * of each other and of the Router — a Handler never knows which other
 * Handlers exist, and the Router never knows what a Handler does.
 *
 * `$context->legacy` is a transitional, opaque transport bag (see
 * docs/DECISIONS.md, D016): Core never reads or writes to it, and no Handler
 * should assume Core-added keys will ever appear in it.
 */
interface HandlerInterface
{
    public function handle(ExecutionContext $context): void;
}
