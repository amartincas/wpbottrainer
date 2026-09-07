<?php

namespace App\Training\Support;

/**
 * Bloque 9 (D052) — resultado de `ConversationTurnResolver::resolve()`:
 * la lista ORDENADA de acciones a ejecutar para este turno.
 */
final readonly class ConversationTurnResolved
{
    /**
     * @param  array<int, ConversationAction>  $actions
     */
    public function __construct(
        public array $actions,
    ) {}
}
