<?php

use App\Training\Enums\ConversationActionType;
use App\Training\Support\ConversationTurnResolver;
use App\Training\Support\SafetySignalDetector;

/**
 * Bloque 9 (D052) — ConversationTurnResolver: prioridad determinista y
 * composición de acciones, sin IA. Cubre explícitamente los casos 41, 44,
 * 45 (parcial, ver TrainingHandlerInterruptionTest para el conteo de
 * llamadas HTTP), 47 (parcial), 50, 53 y 54 exigidos en la aprobación del
 * Bloque 9.
 */
function turnResolver(): ConversationTurnResolver
{
    return new ConversationTurnResolver(new SafetySignalDetector);
}

function actionTypes(\App\Training\Support\ConversationTurnResolved $resolved): array
{
    return array_map(fn ($a) => $a->type->value, $resolved->actions);
}

// ── Safety: prioridad absoluta ──

it('1: a confirmed safety signal yields only EscalateSafety, nothing else', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => 'tengo un fuerte dolor de pecho',
        'reports' => [['exercise_name' => 'Sentadilla', 'not_performed' => false, 'sets' => [], 'rpe' => null, 'note' => null, 'skip_reason' => null, 'uncertain' => false]],
        'session_finished' => false,
        'intents' => ['membership_status'],
        'training_reply' => null,
    ]);

    expect(actionTypes($resolved))->toBe([ConversationActionType::EscalateSafety->value]);
    expect($resolved->actions[0]->safetyReason)->toBe('chest_pain');
});

it('2: a safety_signal_text proposed by the AI but not confirmed by the deterministic detector never escalates', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => 'me siento un poco cansado hoy',
        'reports' => [],
        'session_finished' => false,
        'intents' => ['exercise_question'],
        'training_reply' => 'Explicación de ejemplo.',
    ]);

    expect(actionTypes($resolved))->not->toContain(ConversationActionType::EscalateSafety->value);
    expect(actionTypes($resolved))->toContain(ConversationActionType::SendText->value);
});

// ── report vs intents: independientes, ambos se procesan ──

it('report non-empty yields RecordExecutionReport and the resolver continues evaluating intents', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null,
        'reports' => [['exercise_name' => 'Sentadilla', 'not_performed' => false, 'sets' => [], 'rpe' => null, 'note' => null, 'skip_reason' => null, 'uncertain' => false]],
        'session_finished' => false,
        'intents' => [],
        'training_reply' => null,
    ]);

    expect(actionTypes($resolved))->toBe([ConversationActionType::RecordExecutionReport->value]);
});

it('53: report + membership_status in the same message -> RecordExecutionReport then commercial stub, session never lost', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null,
        'reports' => [['exercise_name' => 'Sentadilla', 'not_performed' => false, 'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]], 'rpe' => null, 'note' => null, 'skip_reason' => null, 'uncertain' => false]],
        'session_finished' => false,
        'intents' => ['membership_status'],
        'training_reply' => null,
    ]);

    expect(actionTypes($resolved))->toBe([
        ConversationActionType::RecordExecutionReport->value,
        ConversationActionType::SendText->value,
    ]);
    expect($resolved->actions[0]->report['reports'])->toHaveCount(1);
});

it('54: report that completes the session (session_finished=true) + membership_status -> report action carries session_finished=true, stub sent after', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null,
        'reports' => [],
        'session_finished' => true,
        'intents' => ['membership_status'],
        'training_reply' => null,
    ]);

    expect(actionTypes($resolved))->toBe([
        ConversationActionType::RecordExecutionReport->value,
        ConversationActionType::SendText->value,
    ]);
    expect($resolved->actions[0]->report['session_finished'])->toBeTrue();
});

// ── training_reply usado una sola vez, sin importar cuántos intents de entrenamiento ──

it('exercise_question alone yields SendText(training_reply)', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['exercise_question'], 'training_reply' => 'Porque tu evaluación reciente indicó progreso.',
    ]);

    expect(actionTypes($resolved))->toBe([ConversationActionType::SendText->value]);
    expect($resolved->actions[0]->text)->toBe('Porque tu evaluación reciente indicó progreso.');
});

it('exercise_question + general_conversation together still produce exactly ONE SendText(training_reply)', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['exercise_question', 'general_conversation'], 'training_reply' => 'Una sola explicación coherente.',
    ]);

    $sendTextActions = array_filter($resolved->actions, fn ($a) => $a->type === ConversationActionType::SendText);
    expect($sendTextActions)->toHaveCount(1);
});

it('exercise_question present but training_reply unusable (empty) never adds a SendText for it', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['exercise_question'], 'training_reply' => '   ',
    ]);

    expect($resolved->actions)->toHaveCount(1);
    expect($resolved->actions[0]->type)->toBe(ConversationActionType::SendText); // fallback ambiguo
});

// ── continue_training: determinista, nunca depende de training_reply ──

it('continue_training yields DeliverSession regardless of training_reply', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['continue_training'], 'training_reply' => null,
    ]);

    expect(actionTypes($resolved))->toBe([ConversationActionType::DeliverSession->value]);
});

it('50: continue_training + exercise_question -> both actions execute, training reply first, then deliver', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['exercise_question', 'continue_training'], 'training_reply' => 'Explicación antes de la entrega.',
    ]);

    expect(actionTypes($resolved))->toBe([
        ConversationActionType::SendText->value,
        ConversationActionType::DeliverSession->value,
    ]);
});

// ── Commercial / FAQ: siempre stub fijo ──

it('membership_status yields the fixed commercial stub, never free text', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['membership_status'], 'training_reply' => null,
    ]);

    expect(actionTypes($resolved))->toBe([ConversationActionType::SendText->value]);
    $stub = $resolved->actions[0]->text;

    // Determinismo: el mismo stub, siempre exactamente igual, en otra llamada.
    $again = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['membership_status'], 'training_reply' => null,
    ]);
    expect($again->actions[0]->text)->toBe($stub);
});

it('faq_question yields the fixed FAQ stub, distinct from the commercial one', function () {
    $commercial = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['membership_status'], 'training_reply' => null,
    ])->actions[0]->text;

    $faq = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['faq_question'], 'training_reply' => null,
    ])->actions[0]->text;

    expect($faq)->not->toBe($commercial);
});

// ── 41: dos intents combinados sin llamada extra ──

it('41: exercise_question + membership_status -> Coach reply then commercial stub, both present', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['exercise_question', 'membership_status'],
        'training_reply' => 'Aumenté las repeticiones porque tu evaluación reciente indicó que podías progresar en volumen.',
    ]);

    expect(actionTypes($resolved))->toBe([
        ConversationActionType::SendText->value,
        ConversationActionType::SendText->value,
    ]);
    expect($resolved->actions[0]->text)->toContain('Aumenté las repeticiones');
});

// ── 44: tres intents, sin límite artificial ──

it('44: three simultaneous intents produce three actions, no artificial cap', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['exercise_question', 'membership_status', 'faq_question'],
        'training_reply' => 'Explicación de entrenamiento.',
    ]);

    expect($resolved->actions)->toHaveCount(3);
});

// ── mensaje ambiguo: nunca reenvía la rutina por defecto ──

it('an ambiguous turn (no report, no recognized intents, no safety) yields exactly the fixed fallback text, never DeliverSession', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => [], 'training_reply' => null,
    ]);

    expect(actionTypes($resolved))->toBe([ConversationActionType::SendText->value]);
});

it('unrecognized/garbage intent values are silently ignored, never crash, degrade to the ambiguous fallback', function () {
    $resolved = turnResolver()->resolve([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => ['some_made_up_intent'], 'training_reply' => null,
    ]);

    expect(actionTypes($resolved))->toBe([ConversationActionType::SendText->value]);
});
