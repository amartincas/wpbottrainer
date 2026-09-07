<?php

use App\Models\Contact;
use App\Models\Reminder;
use App\Models\ReminderSuggestion;
use App\Training\Support\ReminderProactivityGate;

function proactivityGate(): ReminderProactivityGate
{
    return new ReminderProactivityGate;
}

it('allows a proactive offer when nothing blocks it', function () {
    $contact = Contact::factory()->create();

    expect(proactivityGate()->canOffer($contact))->toBeTrue();
});

it('blocks when a ReminderSuggestion is already pending', function () {
    $contact = Contact::factory()->create();
    ReminderSuggestion::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    expect(proactivityGate()->canOffer($contact))->toBeFalse();
});

it('blocks when a Reminder is already active', function () {
    $contact = Contact::factory()->create();
    Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    expect(proactivityGate()->canOffer($contact))->toBeFalse();
});

it('blocks within the proactive cooldown window, regardless of the previous outcome', function () {
    $contact = Contact::factory()->create();
    ReminderSuggestion::factory()->proactive('session_completed')->create([
        'contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id,
        'status' => \App\Training\Enums\ReminderSuggestionStatus::Accepted,
        'created_at' => now()->subHours(10),
    ]);

    expect(proactivityGate()->canOffer($contact))->toBeFalse();
});

it('allows again once the proactive cooldown window has passed', function () {
    $contact = Contact::factory()->create();
    ReminderSuggestion::factory()->proactive('session_completed')->create([
        'contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id,
        'status' => \App\Training\Enums\ReminderSuggestionStatus::Accepted,
        'created_at' => now()->subHours(73),
    ]);

    expect(proactivityGate()->canOffer($contact))->toBeTrue();
});

it('blocks within the (longer) declined cooldown window', function () {
    $contact = Contact::factory()->create();
    $suggestion = ReminderSuggestion::factory()->declined()->create([
        'contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id,
        'created_at' => now()->subHours(100),
    ]);
    $suggestion->forceFill(['updated_at' => now()->subHours(100)])->saveQuietly();

    expect(proactivityGate()->canOffer($contact))->toBeFalse();
});

it('allows again once the declined cooldown window has passed', function () {
    $contact = Contact::factory()->create();
    $suggestion = ReminderSuggestion::factory()->declined()->create([
        'contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id,
    ]);
    $suggestion->forceFill(['updated_at' => now()->subHours(169)])->saveQuietly();

    expect(proactivityGate()->canOffer($contact))->toBeTrue();
});
