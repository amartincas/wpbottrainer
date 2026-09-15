<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Controles P0 de lanzamiento — presupuesto determinista de llamadas de IA
 * durante el onboarding (App\Training\Handlers\TrainingHandler::
 * MAX_ONBOARDING_AI_TURNS = 10).
 *
 * Los helpers de abajo son deliberadamente una copia local, con nombres
 * distintos (prefijo "budget"), de los definidos en
 * tests/Feature/Training/TrainingConversationFlowTest.php: Pest ejecuta
 * todos los archivos de test en el mismo proceso PHP, así que dos funciones
 * globales con el mismo nombre en dos archivos distintos provocarían un
 * fatal error "Cannot redeclare" al correr la suite completa. Se evita así
 * sin tocar TrainingConversationFlowTest.php ni tests/Pest.php (fuera del
 * alcance de esta tarea).
 */
function budgetFakeOnboardingTurn(array $extracted, ?string $nextAction, ?string $response): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'extracted' => $extracted,
        'next_action' => $nextAction,
        'response' => $response,
    ])]]]];
}

function budgetEmptyExtraction(array $overrides = []): array
{
    return array_merge([
        'name' => null, 'goal' => null, 'experience_level' => null,
        'primary_focus' => null, 'secondary_focus' => null, 'training_location' => null,
        'restrictions' => null, 'available_equipment' => null, 'equipment_fully_equipped' => null,
        'sessions_per_week' => null, 'age' => null, 'sex' => null, 'weight_kg' => null,
        'height_cm' => null, 'safety_signal_text' => null,
        'health_declaration_category' => null, 'health_condition_text' => null, 'functional_limitation_text' => null,
    ], $overrides);
}

function sendBudgetTrainingMessage(Tenant $tenant, string $from, ?string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}
it('turn 10 (onboarding_turns 9 -> 10, el límite exacto) todavía permite la llamada de IA', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'onboarding_turns' => 9]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            budgetFakeOnboardingTurn(budgetEmptyExtraction(['goal' => 'build_muscle']), 'ask_experience_level', '¿Ya has entrenado antes?'),
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendBudgetTrainingMessage($tenant, '573001112233', 'Quiero ganar músculo');

    expect($profile->fresh()->onboarding_turns)->toBe(10);
    // Nota: no se asume el TEXTO exacto de la pregunta enviada — TrainingHandler
    // valida el `next_action` de la IA contra el requirement REALMENTE
    // pendiente (`OnboardingRequirementRegistry::firstPendingBlocking()`) y,
    // si no coincide, usa su propia pregunta determinista de fallback en vez
    // del texto libre de la IA (ver `OnboardingConversationService::resolveQuestion()`)
    // — el propósito de este test es solo confirmar que la LLAMADA de IA del
    // turno 10 se permite, no la redacción exacta de la pregunta.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
});

it('turn 11 (onboarding_turns 10 -> 11): no llama a la IA, incrementa el contador, y envía el mensaje fijo de escalamiento', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'onboarding_turns' => 10]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);
    Log::spy();

    // Se incluye una keyword de Training explícita ("quiero entrenar") para
    // no depender de la ruta de clasificación implícita por "onboarding
    // incompleto" de TrainingIntentClassifier — esa ruta usa el método
    // LEGACY TrainingProfile::isOnboardingComplete(), que no conoce el
    // requirement de health_screening (Bloque 5/D048) y por eso puede
    // considerar "completo" un perfil que OnboardingRequirementRegistry (la
    // autoridad real que usa TrainingHandler) todavía ve incompleto —
    // discrepancia preexistente y fuera del alcance de esta tarea P0
    // (TrainingIntentClassifier no está entre los archivos a modificar).
    sendBudgetTrainingMessage($tenant, '573001112233', 'quiero entrenar, un mensaje más');

    expect($profile->fresh()->onboarding_turns)->toBe(11);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
        && str_contains(data_get($request->data(), 'text.body', ''), 'límite de sesiones de configuración automática'));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($message, $context) => $message === 'ONBOARDING_AI_BUDGET_EXCEEDED'
            && $context['tenant_id'] === $tenant->id
            && $context['contact_id'] === $contact->id
            && $context['onboarding_turns'] === 11
            && $context['max_onboarding_ai_turns'] === 10);
});

it('a maximum of 10 real AI calls are ever made across a full onboarding conversation, even if it never completes', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    // El perfil nunca queda completo — cada turno aporta información
    // irrelevante para los requirements bloqueantes, así que el onboarding
    // sigue incompleto turno tras turno hasta agotar el presupuesto.
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    // 12 turnos: los primeros 10 SÍ deben llamar a la IA; los turnos 11 y 12
    // deben caer directo al mensaje fijo, sin AI.
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            budgetFakeOnboardingTurn(budgetEmptyExtraction(), 'ask_name', '¿Cómo te gustaría que te llame?'),
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    // "quiero entrenar" en cada turno — keyword explícita de Training (ver
    // nota en el test anterior sobre por qué no se depende de la ruta de
    // fallback implícita por onboarding incompleto).
    for ($i = 1; $i <= 12; $i++) {
        sendBudgetTrainingMessage($tenant, '573001112233', "quiero entrenar, mensaje número {$i}");
    }

    $aiCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.openai.com'))->count();
    expect($aiCalls)->toBe(10);

    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile->onboarding_turns)->toBe(12);
});

it('once onboarding is completed, the AI budget no longer blocks the flow even if the counter is already high', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    // Perfil completo desde el inicio (Bloque 5: name/goal/experience_level/
    // training_location/available_equipment/health_screening) Y con
    // onboarding_turns muy por encima del presupuesto — el chequeo de
    // presupuesto vive DENTRO del bloque `if (!isOnboardingComplete(...))`,
    // así que un perfil ya completo nunca debe entrar a ese chequeo.
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'health_screening_asked' => true,
        'onboarding_turns' => 500,
    ]);

    Http::fake([
        // Única llamada de IA de este turno: Coach (paso 5, Bloque 9) — el
        // onboarding ya está completo, así que nunca pasa por el bloque de
        // presupuesto.
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'safety_signal_text' => null, 'intents' => ['continue_training'], 'training_reply' => null,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendBudgetTrainingMessage($tenant, '573001112233', 'Dame mi entrenamiento de hoy');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
        && str_contains(data_get($request->data(), 'text.body', ''), 'límite de sesiones de configuración automática'));

    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile->onboarding_turns)->toBe(500); // nunca se toca fuera del bloque de onboarding incompleto
});

it('the counter persists across an abandoned conversation and a later reentry — it is never reset', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'onboarding_turns' => 10]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    // "Abandono": el contacto deja de escribir por un tiempo (simulado con
    // un simple paso de tiempo, sin ningún mecanismo de reset involucrado).
    $this->travel(30)->days();

    // "Reentrada": vuelve a escribir. El presupuesto ya estaba agotado antes
    // de irse y sigue agotado al volver — nunca se reinicia por inactividad.
    // Keyword explícita de Training ("quiero entrenar") — ver nota en el
    // test del turno 11 sobre la discrepancia preexistente en
    // TrainingIntentClassifier, fuera del alcance de esta tarea.
    sendBudgetTrainingMessage($tenant, '573001112233', 'hola, sigo aquí, quiero entrenar');

    expect($profile->fresh()->onboarding_turns)->toBe(11);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
        && str_contains(data_get($request->data(), 'text.body', ''), 'límite de sesiones de configuración automática'));
});

it('the increment happens before the AI call, so an AI provider error still consumes budget for that turn', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'onboarding_turns' => 0]);

    // El proveedor de IA falla (timeout/error) en este turno.
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([], 500),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendBudgetTrainingMessage($tenant, '573001112233', 'Quiero ganar músculo');

    // El incremento ocurre ANTES de la llamada de IA (posición sin cambios,
    // ver TrainingHandler.php) — un error del proveedor no revierte el
    // contador ni "devuelve" presupuesto.
    expect($profile->fresh()->onboarding_turns)->toBe(1);
});

it('once the budget is exhausted, absolutely no HTTP call reaches any AI provider, across several different message shapes', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    // Presupuesto ya agotado (11 > 10) y perfil deliberadamente incompleto,
    // para intentar forzar cualquier camino alterno posible dentro del
    // onboarding (nombre, meta, equipo, ubicación, screening de salud, etc.).
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'onboarding_turns' => 11]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    // Cada mensaje incluye una keyword explícita de Training ("quiero
    // entrenar"/"entreno"/"entrenamiento") — ver nota en el test del turno
    // 11 sobre por qué no se depende de la ruta de fallback implícita por
    // onboarding incompleto de TrainingIntentClassifier.
    $messages = [
        'Me llamo Carlos, quiero entrenar',
        'Quiero ganar músculo, entreno en el gimnasio',
        'Soy principiante, sin lesiones, sin equipo, quiero entrenar',
        'Dame mi entrenamiento de hoy',
    ];

    foreach ($messages as $message) {
        sendBudgetTrainingMessage($tenant, '573001112233', $message);
    }

    $aiCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.openai.com'))->count();
    expect($aiCalls)->toBe(0);

    // Cada uno de los 4 turnos recibió el mismo mensaje fijo de escalamiento.
    $escalationReplies = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com')
            && str_contains(data_get($pair[0]->data(), 'text.body', ''), 'límite de sesiones de configuración automática'))
        ->count();
    expect($escalationReplies)->toBe(count($messages));
});
