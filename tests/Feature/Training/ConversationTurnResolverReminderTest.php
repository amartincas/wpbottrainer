<?php

use App\Training\Enums\ConversationActionType;
use App\Training\Support\ConversationTurnResolver;
use App\Training\Support\SafetySignalDetector;

function reminderTurnResolver(): ConversationTurnResolver
{
    return new ConversationTurnResolver(new SafetySignalDetector);
}

function reminderResultBase(array $overrides): array
{
    return array_merge([
        'safety_signal_text' => null, 'reports' => [], 'session_finished' => false,
        'intents' => [], 'training_reply' => null,
        'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
    ], $overrides);
}

it('reminder_request produces a ProposeReminder action carrying the raw (unresolved) data', function () {
    $resolved = reminderTurnResolver()->resolve(reminderResultBase([
        'intents' => ['reminder_request'], 'reminder_day' => 'tuesday', 'reminder_time' => '19:00', 'reminder_recurrence' => true,
    ]));

    expect($resolved->actions)->toHaveCount(1);
    expect($resolved->actions[0]->type)->toBe(ConversationActionType::ProposeReminder);
    expect($resolved->actions[0]->reminderData)->toBe(['day' => 'tuesday', 'time' => '19:00', 'recurring' => true]);
});

it('reminder_confirmation=true produces an ApplyReminderDecision action, even without an intent value', function () {
    $resolved = reminderTurnResolver()->resolve(reminderResultBase(['reminder_confirmation' => true]));

    expect($resolved->actions)->toHaveCount(1);
    expect($resolved->actions[0]->type)->toBe(ConversationActionType::ApplyReminderDecision);
    expect($resolved->actions[0]->reminderData['decision'])->toBe('confirmation');
    expect($resolved->actions[0]->reminderData['confirmed'])->toBeTrue();
});

it('reminder_confirmation carries an override day/time when the user provided one', function () {
    $resolved = reminderTurnResolver()->resolve(reminderResultBase([
        'reminder_confirmation' => true, 'reminder_time' => '20:00',
    ]));

    expect($resolved->actions[0]->reminderData['time'])->toBe('20:00');
});

it('reminder_cancel and reminder_modify each produce their own ApplyReminderDecision action', function () {
    $cancel = reminderTurnResolver()->resolve(reminderResultBase(['intents' => ['reminder_cancel']]));
    expect($cancel->actions[0]->reminderData['decision'])->toBe('cancel');

    $modify = reminderTurnResolver()->resolve(reminderResultBase(['intents' => ['reminder_modify'], 'reminder_time' => '08:00']));
    expect($modify->actions[0]->reminderData['decision'])->toBe('modify');
    expect($modify->actions[0]->reminderData['time'])->toBe('08:00');
});

it('a reminder request combined with a commercial question in one message still produces both actions from a single classification', function () {
    $resolved = reminderTurnResolver()->resolve(reminderResultBase([
        'intents' => ['reminder_request', 'membership_status'], 'reminder_day' => 'monday', 'reminder_time' => '07:00',
    ]));

    $types = array_map(fn ($a) => $a->type, $resolved->actions);
    expect($types)->toBe([ConversationActionType::ProposeReminder, ConversationActionType::SendText]);
});

it('Safety still cuts everything even when reminder intents are present in the same message', function () {
    $resolved = reminderTurnResolver()->resolve(reminderResultBase([
        'safety_signal_text' => 'tengo un fuerte dolor de pecho',
        'intents' => ['reminder_request'], 'reminder_day' => 'monday', 'reminder_time' => '07:00',
    ]));

    expect($resolved->actions)->toHaveCount(1);
    expect($resolved->actions[0]->type)->toBe(ConversationActionType::EscalateSafety);
});

it('proposing a reminder never produces a DeliverSession/RecordExecutionReport action by itself', function () {
    $resolved = reminderTurnResolver()->resolve(reminderResultBase([
        'intents' => ['reminder_request'], 'reminder_day' => 'monday', 'reminder_time' => '07:00',
    ]));

    $types = array_map(fn ($a) => $a->type, $resolved->actions);
    expect($types)->not->toContain(ConversationActionType::DeliverSession);
    expect($types)->not->toContain(ConversationActionType::RecordExecutionReport);
});
