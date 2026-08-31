<?php

use App\Models\Tenant;
use App\Training\Support\OnboardingConversationService;
use Illuminate\Support\Facades\Http;

/**
 * Extract/Narrate halves of onboarding (Hito 5). The LLM is never trusted
 * blindly: every extracted value is validated before being returned, and any
 * AI failure degrades to a safe, deterministic default rather than crashing.
 */

function fakeExtractionResponse(array $payload): void
{
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload)]]],
        ], 200),
    ]);
}

it('extracts and validates a fully well-formed response', function () {
    fakeExtractionResponse([
        'goal' => 'build_muscle',
        'experience_level' => 'beginner',
        'restrictions' => ['knee'],
        'available_equipment' => [],
        'sessions_per_week' => 3,
        'safety_signal_text' => null,
    ]);

    $result = (new OnboardingConversationService)->extractFields(
        'quiero ganar músculo, soy principiante, me duele la rodilla, sin equipo, 3 veces por semana',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result)->toBe([
        'goal' => 'build_muscle',
        'experience_level' => 'beginner',
        'restrictions' => ['knee'],
        'available_equipment' => [],
        'sessions_per_week' => 3,
        'safety_signal_text' => null,
    ]);
});

it('discards an invalid enum value instead of trusting the LLM', function () {
    fakeExtractionResponse([
        'goal' => 'become_a_bodybuilder_overnight', // not a real TrainingGoal value
        'experience_level' => 'beginner',
        'restrictions' => null,
        'available_equipment' => null,
        'sessions_per_week' => null,
        'safety_signal_text' => null,
    ]);

    $result = (new OnboardingConversationService)->extractFields(
        'quiero ser fisicoculturista de la noche a la mañana',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result['goal'])->toBeNull();
    expect($result['experience_level'])->toBe('beginner');
});

it('discards a non-array restrictions/equipment value', function () {
    fakeExtractionResponse([
        'goal' => null,
        'experience_level' => null,
        'restrictions' => 'no numerado, texto libre',
        'available_equipment' => null,
        'sessions_per_week' => null,
        'safety_signal_text' => null,
    ]);

    $result = (new OnboardingConversationService)->extractFields(
        'algo',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result['restrictions'])->toBeNull();
});

it('discards an out-of-range sessions_per_week', function () {
    fakeExtractionResponse([
        'goal' => null, 'experience_level' => null, 'restrictions' => null,
        'available_equipment' => null, 'sessions_per_week' => 99, 'safety_signal_text' => null,
    ]);

    $result = (new OnboardingConversationService)->extractFields(
        'entreno 99 veces por semana',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result['sessions_per_week'])->toBeNull();
});

it('returns all-null fields when the AI provider fails, without throwing', function () {
    Http::fake(['api.openai.com/*' => Http::response('Server error', 500)]);

    $result = (new OnboardingConversationService)->extractFields(
        'quiero ganar músculo',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result)->toBe([
        'goal' => null, 'experience_level' => null, 'restrictions' => null,
        'available_equipment' => null, 'sessions_per_week' => null, 'safety_signal_text' => null,
    ]);
});

it('never calls the AI provider for an empty message body', function () {
    Http::fake();

    $result = (new OnboardingConversationService)->extractFields(
        '',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result['goal'])->toBeNull();
    Http::assertNothingSent();
});

it('narrates a natural question and trims the AI response', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '  ¿Cuál es tu objetivo? 😊  ']]],
        ], 200),
    ]);

    $question = (new OnboardingConversationService)->nextQuestion('goal', Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($question)->toBe('¿Cuál es tu objetivo? 😊');
});

it('falls back to a canned question when the AI provider fails to narrate', function () {
    Http::fake(['api.openai.com/*' => Http::response('Server error', 500)]);

    $question = (new OnboardingConversationService)->nextQuestion('sessions_per_week', Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($question)->toBeString()->not->toBeEmpty();
    expect($question)->toContain('días a la semana');
});
