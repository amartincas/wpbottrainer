<?php

use App\Models\Contact;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutSessionStatus;

it('belongs to a contact and exposes its workout exercises in order', function () {
    $contact = Contact::factory()->create();
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id]);

    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 2]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'order' => 1]);

    expect($session->contact->is($contact))->toBeTrue();
    expect($session->workoutExercises->pluck('order')->all())->toBe([1, 2]);
});

it('defaults to scheduled and supports completed/skipped states', function () {
    $scheduled = WorkoutSession::factory()->create();
    $completed = WorkoutSession::factory()->completed()->create();
    $skipped = WorkoutSession::factory()->skipped()->create();

    expect($scheduled->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect($completed->status)->toBe(WorkoutSessionStatus::Completed);
    expect($completed->completed_at)->not->toBeNull();
    expect($skipped->status)->toBe(WorkoutSessionStatus::Skipped);
});
