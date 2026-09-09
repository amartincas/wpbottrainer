<?php

namespace App\Training\Context;

/**
 * Hito 14 — DTO propio de Training, escalares puros (`id`/`question`/
 * `answer`). Es la ÚNICA forma en la que `App\CustomerCare\Models\Faq`
 * "cruza" hacia `App\Training` — `CoachContextProvider` es el único
 * archivo que consulta ese modelo y lo mapea aquí; `CoachContext`/
 * `CoachService`/`ConversationTurnResolver` nunca importan nada de
 * `App\CustomerCare` (ver docs/DECISIONS.md).
 */
final readonly class CoachFaqCandidate
{
    public function __construct(
        public int $id,
        public string $question,
        public string $answer,
    ) {}
}
