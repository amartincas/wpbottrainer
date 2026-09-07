<?php

namespace App\Training\Support;

use App\Training\Enums\ConversationActionType;

/**
 * Bloque 9 (D052) — una acción concreta ya resuelta por
 * `ConversationTurnResolver`. `TrainingHandler` solo ejecuta lo que aquí
 * llega — nunca decide por su cuenta qué hacer con `intents`/`report`.
 */
final readonly class ConversationAction
{
    private function __construct(
        public ConversationActionType $type,
        public ?string $text = null,
        public ?array $report = null,
        public ?string $safetyReason = null,
    ) {}

    public static function escalateSafety(string $reason): self
    {
        return new self(ConversationActionType::EscalateSafety, safetyReason: $reason);
    }

    /**
     * @param  array{reports: array, session_finished: bool}  $report
     */
    public static function recordExecutionReport(array $report): self
    {
        return new self(ConversationActionType::RecordExecutionReport, report: $report);
    }

    public static function sendText(string $text): self
    {
        return new self(ConversationActionType::SendText, text: $text);
    }

    public static function deliverSession(): self
    {
        return new self(ConversationActionType::DeliverSession);
    }
}
