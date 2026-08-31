<?php

namespace App\Core\Memory;

/**
 * One piece of memory/context contributed by a ContextProvider, tagged with
 * where it came from and how much it can be trusted — so the LLM (or the
 * Handler building its prompt) can tell a confirmed fact from an inference
 * from an unknown apart, instead of treating everything as equally certain.
 *
 * Core never inspects `$data` — it is opaque, meaningful only to the
 * ContextProvider that produced it and the Handler that consumes it.
 */
final class ContextFragment
{
    public function __construct(
        public readonly string $label,
        public readonly mixed $data,
        public readonly string $source,
        public readonly string $confidence,
    ) {}
}
