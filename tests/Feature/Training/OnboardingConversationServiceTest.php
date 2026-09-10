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

/**
 * Payload crudo "extracted" completo (Hito 8.3) — representa lo que la IA
 * devolvería en el JSON, con todos los campos presentes (a diferencia de
 * combinedPayload(), que solo declara los 6 originales por brevedad en los
 * tests heredados del Hito 5.1).
 */
function emptyExtractedForTest(array $overrides = []): array
{
    return array_merge([
        'name' => null, 'goal' => null, 'experience_level' => null,
        'primary_focus' => null, 'secondary_focus' => null, 'training_location' => null,
        'restrictions' => null, 'available_equipment' => null, 'equipment_fully_equipped' => null,
        'sessions_per_week' => null, 'age' => null, 'sex' => null, 'weight_kg' => null,
        'height_cm' => null, 'safety_signal_text' => null,
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
        'name' => null, 'goal' => 'build_muscle', 'experience_level' => 'beginner',
        'primary_focus' => null, 'secondary_focus' => null,
        'training_location' => null, 'available_equipment' => [], 'equipment_fully_equipped' => null,
        'restrictions' => ['knee'], 'sessions_per_week' => 3,
        'age' => null, 'sex' => null, 'weight_kg' => null, 'height_cm' => null, 'safety_signal_text' => null,
        'health_declaration_category' => null, 'health_condition_text' => null, 'functional_limitation_text' => null,
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
            'name' => null, 'goal' => null, 'experience_level' => null,
            'primary_focus' => null, 'secondary_focus' => null,
            'training_location' => null, 'available_equipment' => null, 'equipment_fully_equipped' => null,
            'restrictions' => null, 'sessions_per_week' => null,
            'age' => null, 'sex' => null, 'weight_kg' => null, 'height_cm' => null,
            'safety_signal_text' => null,
            'health_declaration_category' => null, 'health_condition_text' => null, 'functional_limitation_text' => null,
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

// ── Hito 8.3: nombre, training_location, equipo amplio, datos físicos ──

it('extracts name, training_location and equipment_fully_equipped from a single compound message', function () {
    // "Entreno en un gimnasio y tengo de todo" — el caso real reportado en
    // el E2E comercial. Varios campos en un mismo turno.
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest([
            'name' => 'Ana',
            'training_location' => 'gym',
            'equipment_fully_equipped' => true,
        ]),
        'next_action' => 'ask_experience_level',
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond(
        'Me llamo Ana, entreno en un gimnasio y tengo de todo',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result['extracted']['name'])->toBe('Ana');
    expect($result['extracted']['training_location'])->toBe('gym');
    expect($result['extracted']['equipment_fully_equipped'])->toBeTrue();
    expect($result['extracted']['available_equipment'])->toBeNull(); // nunca se inventa una lista
});

it('keeps equipment_fully_equipped false/null when the user names specific equipment instead of declaring broad availability', function () {
    // "Solo pesas" — equipo específico, no una declaración de "todo". Hito
    // 9.3 (post-deploy): la IA ya traduce al vocabulario cerrado de
    // Equipment (ver la tabla del prompt), nunca deja "pesas" en español libre.
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest([
            'available_equipment' => ['dumbbells'],
            'equipment_fully_equipped' => null,
        ]),
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond('solo pesas', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result['extracted']['available_equipment'])->toBe(['dumbbells']);
    expect($result['extracted']['equipment_fully_equipped'])->toBeNull();
});

it('discards an invalid training_location value instead of trusting the LLM', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['training_location' => 'un_lugar_inventado']),
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result['extracted']['training_location'])->toBeNull();
});

it('extracts physical stats when given, and leaves them null when the user declines', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['age' => 30, 'sex' => 'female', 'weight_kg' => 65.5, 'height_cm' => 168]),
        'next_action' => 'complete_onboarding',
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond(
        '30 años, mujer, 65.5 kg, 168 cm',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result['extracted']['age'])->toBe(30);
    expect($result['extracted']['sex'])->toBe('female');
    expect($result['extracted']['weight_kg'])->toBe(65.5);
    expect($result['extracted']['height_cm'])->toBe(168);
});

it('discards physical stats outside a plausible range instead of trusting the LLM', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['age' => 250, 'weight_kg' => 900, 'height_cm' => 5]),
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result['extracted']['age'])->toBeNull();
    expect($result['extracted']['weight_kg'])->toBeNull();
    expect($result['extracted']['height_cm'])->toBeNull();
});

it('the combined prompt explicitly instructs how to handle broad/ambiguous equipment availability without enumerating', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'tengo de todo')
            && str_contains($systemPrompt, 'equipment_fully_equipped')
            && str_contains($systemPrompt, 'solo pesas');
    });
});

// ── Hito 9.3 (post-deploy): available_equipment usa el vocabulario cerrado
// Equipment — hallazgo real: antes se guardaba texto libre y nunca
// calzaba contra Exercise.equipment_needed (siempre canónico). ─────────

it('accepts valid canonical Equipment values for available_equipment', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['available_equipment' => ['dumbbells', 'resistance_bands', 'smith_machine']]),
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result['extracted']['available_equipment'])->toBe(['dumbbells', 'resistance_bands', 'smith_machine']);
});

it('discards an available_equipment value the LLM left in free-text Spanish instead of the closed vocabulary', function () {
    // Simula el caso real que causó el bug: la IA (o una versión anterior
    // del prompt) devuelve la palabra tal cual la dijo el usuario.
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['available_equipment' => ['máquinas', 'pesas']]),
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    // Ninguno de los dos es un valor válido de Equipment::class — se
    // descartan, nunca se persiste equipo que TrainingEngine no reconocería.
    expect($result['extracted']['available_equipment'])->toBe([]);
});

it('accepts an empty available_equipment as a valid, complete answer — "no tengo nada" is not "not answered"', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['available_equipment' => []]),
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond('nada', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result['extracted']['available_equipment'])->toBe([]);
});

it('the combined prompt instructs translating equipment to the closed Equipment vocabulary, covering the full official YMove list', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'mancuernas')
            && str_contains($systemPrompt, 'dumbbells')
            && str_contains($systemPrompt, 'máquina smith')
            && str_contains($systemPrompt, 'smith_machine')
            && str_contains($systemPrompt, 'omítelo del arreglo');
    });
});

// ── Ronda 2 (piloto real), Cambio 1: acceso amplio a equipo de gimnasio,
// y preferencia vs. disponibilidad real. ───────────────────────────────

it('the combined prompt invites a full-access answer instead of enumerating gym equipment, when available_equipment is pending and the location is already known to be a gym', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond(
        'algo',
        ['training_location' => 'gym'],
        Tenant::factory()->create(['ai_provider' => 'openai']),
        'available_equipment',
    );

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'acceso a la mayoría del equipo de un gimnasio')
            && str_contains($systemPrompt, 'equipment_fully_equipped');
    });
});

it('never adds the gym-equipment hint when the pending field is not available_equipment, even with a known gym location', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond(
        'algo',
        ['training_location' => 'gym'],
        Tenant::factory()->create(['ai_provider' => 'openai']),
        'goal',
    );

    Http::assertSent(fn ($request) => ! str_contains(
        data_get($request->data(), 'messages.0.content', ''),
        'acceso a la mayoría del equipo de un gimnasio'
    ));
});

it('never adds the gym-equipment hint when the known location is not gym, even with available_equipment pending', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond(
        'algo',
        ['training_location' => 'home'],
        Tenant::factory()->create(['ai_provider' => 'openai']),
        'available_equipment',
    );

    Http::assertSent(fn ($request) => ! str_contains(
        data_get($request->data(), 'messages.0.content', ''),
        'acceso a la mayoría del equipo de un gimnasio'
    ));
});

it('the combined prompt explicitly instructs that a mere equipment preference, without an explicit negation, never modifies available_equipment or equipment_fully_equipped', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'PREFIERE')
            && str_contains($systemPrompt, 'NEGACIÓN explícita');
    });
});

it('end-to-end: a preference statement ("prefiero mancuernas") without negation leaves available_equipment/equipment_fully_equipped untouched, per what the (simulated) LLM correctly returns', function () {
    // Simula el comportamiento esperado de un LLM que sigue la nueva regla
    // del prompt: una preferencia sin negación no se traduce a ninguno de
    // los dos campos — ambos quedan null (todavía sin responder), nunca se
    // fuerza "dumbbells" como si fuera disponibilidad exclusiva.
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['available_equipment' => null, 'equipment_fully_equipped' => null]),
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond(
        'prefiero entrenar con mancuernas',
        ['training_location' => 'gym', 'equipment_fully_equipped' => true],
        Tenant::factory()->create(['ai_provider' => 'openai']),
        'available_equipment',
    );

    expect($result['extracted']['available_equipment'])->toBeNull();
    expect($result['extracted']['equipment_fully_equipped'])->toBeNull();
});

// ── Hito 8.4: objetivos específicos (primary_focus/secondary_focus) ────

it('translates a simple muscle-focus phrase into the closed MuscleFocus vocabulary', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['primary_focus' => ['glutes']]),
        'next_action' => 'ask_training_location',
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond(
        'quiero aumentar glúteos',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result['extracted']['primary_focus'])->toBe(['glutes']);
});

it('accepts an empty primary_focus as a valid, complete answer — "no preference" is not "not answered"', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['primary_focus' => []]),
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond(
        'quiero trabajar todo por igual',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result['extracted']['primary_focus'])->toBe([]);
});

it('discards a primary_focus value outside the closed MuscleFocus vocabulary instead of trusting the LLM', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['primary_focus' => ['glutes', 'un_valor_inventado']]),
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result['extracted']['primary_focus'])->toBe(['glutes']);
});

it('extracts a compound focus ("piernas") and a lower-emphasis secondary_focus in the same message', function () {
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest([
            'primary_focus' => ['quads', 'hamstrings', 'glutes', 'calves'],
            'secondary_focus' => ['back'],
        ]),
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond(
        'sobre todo piernas, y algo de espalda también',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($result['extracted']['primary_focus'])->toBe(['quads', 'hamstrings', 'glutes', 'calves']);
    expect($result['extracted']['secondary_focus'])->toBe(['back']);
});

it('uses the AI response for primary_focus when next_action matches the real missing field', function () {
    $service = new OnboardingConversationService;

    $question = $service->resolveQuestion('primary_focus', 'ask_primary_focus', '¿Alguna zona que quieras priorizar?');

    expect($question)->toBe('¿Alguna zona que quieras priorizar?');
    expect($service->usedAiResponse('primary_focus', 'ask_primary_focus', '¿Alguna zona que quieras priorizar?'))->toBeTrue();
});

it('falls back to the canned primary_focus question when next_action does not match', function () {
    $service = new OnboardingConversationService;

    $question = $service->resolveQuestion('primary_focus', 'ask_goal', '¿Cuál es tu objetivo?');

    expect($question)->toBe(
        '¿Hay alguna zona de tu cuerpo que quieras priorizar especialmente? Por ejemplo glúteos, piernas, espalda o abdomen — o si prefieres trabajar todo por igual, también dime.'
    );
});

it('the combined prompt never exposes the internal primary_focus/secondary_focus terms as user-facing language', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'glúteos')
            && str_contains($systemPrompt, 'piernas')
            && str_contains($systemPrompt, 'Nunca uses los términos técnicos');
    });
});

// ── Hito 9.3 (fix post-E2E): objetivo vs. foco, y respuestas negativas ──

it('tells the AI which question is pending, so a short ambiguous answer can be classified with context', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond(
        'piernas',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
        'goal',
    );

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'La pregunta que ACABAS de hacerle al usuario')
            && str_contains($systemPrompt, 'objetivo general de entrenamiento');
    });
});

it('never injects a "pending question" line when no pending field is given (backward compatible)', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return ! str_contains($systemPrompt, 'La pregunta que ACABAS de hacerle al usuario');
    });
});

it('the combined prompt explicitly instructs the AI to route a focus-shaped answer to a pending goal question into primary_focus, never into goal', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond(
        'piernas',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
        'goal',
    );

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'SIN mencionar ninguno de los 4 objetivos generales de la lista cerrada, extrae esas zonas en "primary_focus"')
            && str_contains($systemPrompt, 'nunca lo inventes ni lo fuerces a partir de una respuesta de foco');
    });
});

it('end-to-end: a focus-shaped answer to the pending goal question is extracted as primary_focus, goal stays null, and next_action asks for goal again', function () {
    // Simula lo que se espera de un LLM que sigue la nueva instrucción del
    // prompt: no fuerza "piernas" dentro de goal, lo extrae como foco, y
    // sigue pidiendo el objetivo real.
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['primary_focus' => ['quads', 'hamstrings', 'glutes', 'calves']]),
        'next_action' => 'ask_goal',
        'response' => 'Anotado que quieres priorizar piernas. ¿Y cuál es tu objetivo principal: perder peso, ganar músculo, condición física o resistencia?',
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond(
        'piernas',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
        'goal',
    );

    expect($result['extracted']['goal'])->toBeNull();
    expect($result['extracted']['primary_focus'])->toBe(['quads', 'hamstrings', 'glutes', 'calves']);

    $service = new OnboardingConversationService;
    // El campo real pendiente sigue siendo 'goal' (primary_focus no tiene
    // prioridad sobre goal) — resolveQuestion() debe usar la respuesta de
    // la IA porque next_action coincide con el campo real pendiente.
    $question = $service->resolveQuestion('goal', $result['next_action'], $result['response']);
    expect($question)->toBe($result['response']);
    expect($service->usedAiResponse('goal', $result['next_action'], $result['response']))->toBeTrue();
});

it('the combined prompt explicitly instructs the AI to accept a bare negation to the pending restrictions question as restrictions: []', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond(
        'no',
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
        'restrictions',
    );

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'interpreta esto como una respuesta explícita de "sin restricciones" y usa restrictions: []')
            && str_contains($systemPrompt, 'no una lista fija de frases');
    });
});

// 27. end-to-end, una por cada frase de ejemplo pedida explícitamente (Hito 9.3)
it('end-to-end: common negative-response phrasings for restrictions are all accepted as restrictions: [], never re-asked as unanswered', function (string $phrase) {
    fakeCombinedResponse(combinedPayload([
        'extracted' => emptyExtractedForTest(['restrictions' => []]),
        'next_action' => 'complete_onboarding',
        'response' => '¡Perfecto!',
    ]));

    $result = (new OnboardingConversationService)->extractAndRespond(
        $phrase,
        [],
        Tenant::factory()->create(['ai_provider' => 'openai']),
        'restrictions',
    );

    // [] (respondido, sin ninguna) — nunca null (todavía sin responder).
    expect($result['extracted']['restrictions'])->toBe([]);
})->with([
    'no', 'No', 'ninguna', 'ninguno', 'no tengo', 'nada', 'no, ninguna',
]);

// ── H16.1 (Cambio 1): "perfil listo" reutiliza la MISMA llamada ────────

it('the combined prompt instructs a brief, honest closing response for the completion turn (next_action=complete_onboarding), without promising immediate delivery or mentioning Trial/access', function () {
    fakeCombinedResponse(combinedPayload());

    (new OnboardingConversationService)->extractAndRespond('algo', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'complete_onboarding')
            && str_contains($systemPrompt, 'NUNCA prometas que la rutina llega de inmediato')
            && str_contains($systemPrompt, 'NUNCA menciones Trial, membresía ni acceso');
    });
});

it('resolveProfileReadyMessage() returns the AI response when next_action is complete_onboarding and the response is usable', function () {
    $service = new OnboardingConversationService;

    $message = $service->resolveProfileReadyMessage('complete_onboarding', '¡Perfecto, ya tengo todo lo que necesito!');

    expect($message)->toBe('¡Perfecto, ya tengo todo lo que necesito!');
});

it('resolveProfileReadyMessage() returns null (use the deterministic fallback) when next_action is not complete_onboarding', function () {
    $service = new OnboardingConversationService;

    // La IA todavía cree que falta algo — nunca se usa su `response` como
    // "perfil listo", aunque el código YA determinó independientemente que
    // el onboarding sí terminó (ver TrainingHandler).
    expect($service->resolveProfileReadyMessage('ask_health_screening', 'Cuéntame si tienes alguna lesión.'))->toBeNull();
    expect($service->resolveProfileReadyMessage(null, 'algo'))->toBeNull();
});

it('resolveProfileReadyMessage() returns null when the response is empty, blank, or too long, even if next_action matches', function () {
    $service = new OnboardingConversationService;

    expect($service->resolveProfileReadyMessage('complete_onboarding', ''))->toBeNull();
    expect($service->resolveProfileReadyMessage('complete_onboarding', '   '))->toBeNull();
    expect($service->resolveProfileReadyMessage('complete_onboarding', null))->toBeNull();
    expect($service->resolveProfileReadyMessage('complete_onboarding', str_repeat('a', 301)))->toBeNull();
});
