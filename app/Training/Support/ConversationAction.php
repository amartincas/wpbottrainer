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
        public ?array $reminderData = null,
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

    /**
     * Hito 10 — datos CRUDOS (día/hora/recurrencia ya extraídos, todavía no
     * resueltos) de una petición de recordatorio. `TrainingHandler` es
     * quien resuelve con `ReminderTimeResolver`/`TimezoneResolver` y
     * persiste — este resolver nunca toca base de datos ni conoce Contact.
     *
     * @param  array{day: ?string, time: ?string, recurring: bool}  $data
     */
    public static function proposeReminder(array $data): self
    {
        return new self(ConversationActionType::ProposeReminder, reminderData: $data);
    }

    /**
     * Hito 10 — cubre confirmación (con posible override), cancelación y
     * modificación de un Reminder ya existente — `TrainingHandler` decide
     * cuál aplica según qué exista realmente para el contacto (una
     * `ReminderSuggestion` pendiente vs. un `Reminder` activo), nunca esta
     * clase ni el resolver.
     *
     * @param  array{decision: string, confirmed: ?bool, day: ?string, time: ?string}  $data
     *         decision: 'confirmation' | 'cancel' | 'modify'
     */
    public static function applyReminderDecision(array $data): self
    {
        return new self(ConversationActionType::ApplyReminderDecision, reminderData: $data);
    }

    /**
     * Hito 10 (D053, corrección post-revisión) — Triggers 1/3 de
     * proactividad: transporta CUÁL señal se detectó (`trigger_reason`),
     * nunca decide si corresponde ofrecer algo — `TrainingHandler` consulta
     * `ReminderProactivityGate` (código) antes de crear cualquier
     * `ReminderSuggestion`. Nunca crea un `Reminder` directamente.
     *
     * @param  array{trigger_reason: string}  $data
     */
    public static function offerProactiveReminder(array $data): self
    {
        return new self(ConversationActionType::OfferProactiveReminder, reminderData: $data);
    }
}
