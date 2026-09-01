<?php

use App\Models\Tenant;
use App\Training\Support\OnboardingConversationService;
use Illuminate\Support\Facades\Http;

/**
 * Hito 5.1: Extract + Narrate fusionados en UNA sola llamada de IA
 * (extractAndRespond()). `next_action`/`response` son SEÑALES, nunca
 * autoridad — resolveQuestion() es el único punto que decide qué texto
 * enviar, comparando `next_action` contra el campo que el código YA
 * determinó que falta (nunca al revés). Ver docs/DECISIONS.md (D026).
 */

function fakeCombinedResponse(array $payload): void
{
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload)]]],
        ], 200),
    ]);
}

function combinedPayload(array $overrides = []): array
{
    return array_merge([
        'extracted' => [
            'goal' => null, 'experience_level' => null, 'restrictions' => null,
            'available_equipment' => null, 'sessions_per_week' => null, 'safety_signal_text' => null,
        ],
        'next_action' => 'ask_goal',
        'response' => '¿Cuál es tu objetivo principal?',
    ], $overrides);
}

// 1. Extracción válida
it('extracts and validates a fully well-formed response', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => [
            'goal' => 'build_muscle', 'experience_level' => 'beginner', 'restrictions' => ['knee'],
            'available_equipment' => [], 'sessions_per_week' => 3, 'safety_signal_text' => null,
        ],
        'next_action' => 'complete_onboarding',
        'response' => '¡Perfecto, ya tengo todo!',
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond(
        'quiero ganar músculo, soy principiante, me duele la rodilla, sin equipo, 3 veces por semana',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result['extracted'])->toBe([
        'goal' => 'build_muscle', 'experience_level' => 'beginner', 'restrictions' => ['knee'],
        'available_equipment' => [], 'sessions_per_week' => 3, 'safety_signal_text' => null,
    ]);
    expect($result['next_action'])->toBe('complete_onboarding');
    expect($result['response'])->toBe('¡Perfecto, ya tengo todo!');
});

// 2. Extracción inválida (valores fuera del vocabulario permitido se descartan)
it('discards invalid extracted values instead of trusting the LLM', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => [
            'goal' => 'become_a_bodybuilder_overnight', 'experience_level' => 'beginner',
            'restrictions' => 'no numerado, texto libre', 'available_equipment' => null,
            'sessions_per_week' => 99, 'safety_signal_text' => null,
        ],
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result['extracted']['goal'])->toBeNull(); // enum inválido descartado
    expect($result['extracted']['experience_level'])->toBe('beginner'); // válido, se conserva
    expect($result['extracted']['restrictions'])->toBeNull(); // no es array, descartado
    expect($result['extracted']['sessions_per_week'])->toBeNull(); // fuera de rango 1-14, descartado
});

// 3. next_action correcto + response válido → se usa la respuesta de la IA
it('uses the AI response when next_action matches the real missing field', function () {
    $service = new OnboardingConversationService;

    $question = $service->resolveQuestion('experience_level', 'ask_experience_level', '¿Ya has entrenado antes?');

    expect($question)->toBe('¿Ya has entrenado antes?');
    expect($service->usedAiResponse('experience_level', 'ask_experience_level', '¿Ya has entrenado antes?'))->toBeTrue();
});

// 4. next_action incorrecto → se descarta, se usa el fallback
it('falls back to the canned question when next_action does not match the real missing field', function () {
    $service = new OnboardingConversationService;

    // La IA cree que falta "goal", pero el código ya determinó que en
    // realidad falta "sessions_per_week" — la respuesta de la IA no aplica.
    $question = $service->resolveQuestion('sessions_per_week', 'ask_goal', '¿Cuál es tu objetivo?');

    expect($question)->toBe('¿Cuántos días a la semana puedes entrenar?');
    expect($service->usedAiResponse('sessions_per_week', 'ask_goal', '¿Cuál es tu objetivo?'))->toBeFalse();
});

// 5. next_action ausente → se descarta, se usa el fallback
it('falls back to the canned question when next_action is missing', function () {
    $service = new OnboardingConversationService;

    $question = $service->resolveQuestion('goal', null, 'Una respuesta cualquiera');

    expect($question)->toBe('¿Cuál es tu objetivo principal: perder peso, ganar músculo, mejorar tu condición física general o resistencia?');
});

// 6. "complete_onboarding" nunca completa el onboarding por sí mismo
it('never lets complete_onboarding stand in for a real missing field question', function () {
    $service = new OnboardingConversationService;

    // La IA dice que ya terminó, pero el código sabe que TODAVÍA falta
    // "restrictions" (por eso se llama a resolveQuestion() en primer
    // lugar) — "complete_onboarding" no coincide con ningún NEXT_ACTION_MAP,
    // así que estructuralmente nunca puede "coincidir" con un campo real.
    $question = $service->resolveQuestion('restrictions', 'complete_onboarding', 'Genial, ya está todo listo.');

    expect($question)->toBe('¿Tienes alguna lesión, dolor o limitación física que debamos tener en cuenta?');
    expect($service->usedAiResponse('restrictions', 'complete_onboarding', 'Genial, ya está todo listo.'))->toBeFalse();
});

// 7. response vacío → se descarta aunque next_action coincida
it('falls back when the AI response is empty even if next_action matches', function () {
    $service = new OnboardingConversationService;

    expect($service->resolveQuestion('goal', 'ask_goal', ''))->toBe(
        '¿Cuál es tu objetivo principal: perder peso, ganar músculo, mejorar tu condición física general o resistencia?'
    );
    expect($service->resolveQuestion('goal', 'ask_goal', null))->toBe(
        '¿Cuál es tu objetivo principal: perder peso, ganar músculo, mejorar tu condición física general o resistencia?'
    );
});

// 8. response excesivamente largo → se descarta aunque next_action coincida
it('falls back when the AI response is unreasonably long even if next_action matches', function () {
    $service = new OnboardingConversationService;
    $tooLong = str_repeat('a', 301);

    $question = $service->resolveQuestion('goal', 'ask_goal', $tooLong);

    expect($question)->toBe('¿Cuál es tu objetivo principal: perder peso, ganar músculo, mejorar tu condición física general o resistencia?');
});

// 9. JSON inválido → resultado vacío, sin lanzar excepción
it('returns an empty result when the AI response is not valid JSON', function () {
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'esto no es JSON']]]], 200)]);

    $result = (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result['extracted']['goal'])->toBeNull();
    expect($result['next_action'])->toBeNull();
    expect($result['response'])->toBeNull();
});

// 10. Error HTTP → resultado vacío, sin lanzar excepción ni bloquear el onboarding
it('returns an empty result when the AI provider fails, without throwing', function () {
    Http::fake(['api.openai.com/*' => Http::response('Server error', 500)]);

    $result = (new OnboardingConversationService)->extractAndRespond('quiero ganar músculo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result)->toBe([
        'extracted' => [
            'goal' => null, 'experience_level' => null, 'restrictions' => null,
            'available_equipment' => null, 'sessions_per_week' => null, 'safety_signal_text' => null,
        ],
        'next_action' => null,
        'response' => null,
    ]);
});

it('never calls the AI provider for an empty message body', function () {
    Http::fake();

    $result = (new OnboardingConversationService)->extractAndRespond('', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result['extracted']['goal'])->toBeNull();
    Http::assertNothingSent();
});

// 13. restrictions y safety_signal_text son independientes — el hallazgo real del Hito 8/E2E
it('keeps restrictions and safety_signal_text independent — an ordinary pain mention fills restrictions, not a safety alarm', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => [
            'goal' => null, 'experience_level' => null,
            'restrictions' => ['dolor en las rodillas'], 'available_equipment' => null,
            'sessions_per_week' => null, 'safety_signal_text' => null,
        ],
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond(
        'tengo dolor en las rodillas',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result['extracted']['restrictions'])->toBe(['dolor en las rodillas']);
    expect($result['extracted']['safety_signal_text'])->toBeNull();
});

it('the combined prompt explicitly instructs that restrictions and safety_signal_text are independent', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'son campos independientes')
            && str_contains($systemPrompt, 'dolor en las rodillas');
    });
});
