<?php

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Enums\TrainingGoal;
use App\Training\Onboarding\OnboardingConversationComposer;
use App\Training\Onboarding\Requirements\PrimaryFocusRequirement;
use App\Training\Support\OnboardingConversationService;

// ── E: el Composer produce un fragmento usando contexto real, sin inventar ──

it('E: the Composer builds an opportunistic invitation from real QuestionContext data, never inventing text', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'goal' => TrainingGoal::BuildMuscle]);

    $context = (new PrimaryFocusRequirement)->questionContext($profile, $contact);
    $fragment = (new OnboardingConversationComposer)->describeOpportunisticInvitation($context);

    expect($fragment)->toContain($context->purpose);
    // Nunca es una segunda pregunta obligatoria — el fragmento debe dejarlo explícito.
    expect($fragment)->toContain('sin insistir');
});

it('the opportunistic invitation is never the fallback question — that is a mecanismo distinto, de contingencia', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $context = (new PrimaryFocusRequirement)->questionContext($profile, $contact);
    $fragment = (new OnboardingConversationComposer)->describeOpportunisticInvitation($context);

    expect($fragment)->not->toBe($context->fallbackQuestion);
});

// ── F: la IA no puede sustituir el requirement que el sistema decidió preguntar ──

it('F: the AI cannot substitute the requirement the system decided to ask — even naming the invited opportunistic field instead', function () {
    // El sistema decidió que falta 'goal' (bloqueante). La IA, en cambio,
    // devuelve next_action='ask_primary_focus' (el oportunista invitado este
    // turno) — resolveQuestion() nunca deja que eso reemplace la pregunta
    // real, cae al fallback determinista de 'goal'.
    $question = (new OnboardingConversationService)->resolveQuestion('goal', 'ask_primary_focus', 'Contame qué zona quieres priorizar');

    expect($question)->toBe(OnboardingConversationService::fallbackQuestionFor('goal'));
});

it('fallbackQuestionFor() is the single source of truth reused by both resolveQuestion() and QuestionContext', function () {
    foreach (['name', 'goal', 'experience_level', 'training_location', 'available_equipment', 'restrictions', 'sessions_per_week', 'primary_focus', 'physical_stats', 'health_screening'] as $key) {
        expect(OnboardingConversationService::fallbackQuestionFor($key))->not->toBe('');
    }
});
