<?php

namespace App\Training\Enums;

/**
 * Bloque 9 (D052) — vocabulario cerrado de las acciones que
 * `ConversationTurnResolver` puede componer para un turno. Nunca decidido
 * por el LLM — es el resultado de aplicar la prioridad determinista del
 * Bloque 9 sobre `safety_signal_text`/`report`/`intents`.
 */
enum ConversationActionType: string
{
    case EscalateSafety = 'escalate_safety';
    case RecordExecutionReport = 'record_execution_report';
    case SendText = 'send_text';
    case DeliverSession = 'deliver_session';

    /** Hito 10 — ver App\Training\Support\ConversationAction. */
    case ProposeReminder = 'propose_reminder';
    case ApplyReminderDecision = 'apply_reminder_decision';

    /**
     * Hito 10 (D053, corrección post-revisión) — Triggers 1/3 de
     * proactividad (`DetectedIntentType::MentionedForgettingToTrain`/
     * `AskedWhenToTrain`): una señal, nunca una petición explícita — ver
     * App\Training\Support\ConversationAction::offerProactiveReminder().
     */
    case OfferProactiveReminder = 'offer_proactive_reminder';
}
