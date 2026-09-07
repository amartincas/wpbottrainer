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
}
