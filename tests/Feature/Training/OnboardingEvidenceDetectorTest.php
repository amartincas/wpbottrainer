<?php

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
use App\Training\Support\OnboardingEvidenceDetector;
use Illuminate\Support\Facades\Http;

/**
 * Hito O2 (Onboarding sticky state / deterministic backstop, diseño formal
 * aprobado, Fase 1 — exclusivamente `training_location`) — cubre las 4
 * capas exigidas: A) detector puro contra el corpus de 24 casos aprobado,
 * B) precedencia LLM > Profile > Backstop, C) integración real vía
 * TrainingHandler (reutiliza `fakeOnboardingTurn()`/`emptyExtraction()`/
 * `sendTrainingMessage()` de TrainingConversationFlowTest.php — mismos
 * helpers globales, sin duplicar infraestructura de test), D) aislamiento
 * arquitectónico (mismo patrón "grep del código fuente real" que
 * OnboardingRequirementArchitectureTest.php).
 */
function evidenceDetector(): OnboardingEvidenceDetector
{
    return new OnboardingEvidenceDetector;
}

function oedReadyProfile(Contact $contact, array $overrides = []): TrainingProfile
{
    return TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'goal' => TrainingGoal::BuildMuscle,
        'experience_level' => ExperienceLevel::Beginner,
        'training_location' => null,
    ], $overrides));
}

// ============================================================
// A. DETECTOR PURO — corpus de 24 casos aprobado
// ============================================================

it('detectTrainingLocation(): corpus case', function (string $text, ?string $expected) {
    expect(evidenceDetector()->detectTrainingLocation($text))->toBe($expected);
})->with([
    // ── Positivas ──
    '1. mensaje real del bug reportado' => ['Entreno en gimnasio con peso libre y máquinas', 'gym'],
    '2. entreno en el gimnasio' => ['entreno en el gimnasio', 'gym'],
    '3. voy al gimnasio' => ['Voy al gimnasio', 'gym'],
    '4. entreno en casa' => ['entreno en casa', 'home'],
    '5. entreno al aire libre' => ['entreno al aire libre', 'outdoor'],
    '6. quiero entrenar en gimnasio' => ['quiero entrenar en gimnasio', 'gym'],
    '7. quiero entrenar en casa' => ['quiero entrenar en casa', 'home'],
    '8. voy a entrenar al gym' => ['voy a entrenar al gym', 'gym'],
    '9. suelo entrenar en casa' => ['suelo entrenar en casa', 'home'],
    '10. hago ejercicio en el gimnasio' => ['hago ejercicio en el gimnasio', 'gym'],
    '11. prefijo libre antes de "entrenar"' => ['Voy a comprar mancuernas para entrenar en casa', 'home'],
    '12. múltiples coincidencias — gana la última' => ['Antes entrenaba en el gimnasio pero ahora entreno en casa', 'home'],
    // ── Negativas (sin evidencia) ──
    '13. sin mención de ubicación' => ['Quiero ganar músculo y estar más fuerte', null],
    '14. sin ancla ni ubicación' => ['Soy principiante en esto', null],
    '15. habla de equipo, no de ubicación' => ['Tengo mancuernas y bandas', null],
    // ── Ambiguas (mención sin declaración válida) ──
    '16. sujeto posesivo, no declaración' => ['Mi gimnasio tiene máquinas y pesas', null],
    '17. adversarial: orden invertido (ubicación antes que ancla)' => ['En el gimnasio hago sentadillas', null],
    '18. "estoy" excluido del vocabulario de anclas' => ['Estoy en el gimnasio ahora mismo', null],
    '19. "estoy" excluido, variante' => ['Cuando estoy en el gimnasio me siento motivado', null],
    '20. "afuera" excluido (solo cuenta "aire libre")' => ['Afuera está lloviendo, no puedo salir', null],
    '21. "afuera" excluido, sin ancla de entrenamiento' => ['Salí afuera un rato a caminar', null],
    '22. "casa de X" excluida por lookahead negativo' => ['entreno en casa de mi amigo', null],
    // ── Adversariales ──
    '23. guard de negación — nunca infiere el valor opuesto' => ['No entreno en gimnasio, prefiero mi casa', null],
    '24. "voy" solo válido para gym, nunca para casa' => ['Voy a mi casa a descansar', null],
]);

// ============================================================
// B. PRECEDENCIA — fillGaps()
// ============================================================

it('B1: LLM ya tiene valor este turno — el detector nunca sobrescribe, aunque el texto sugiera otra ubicación', function () {
    $contact = Contact::factory()->create();
    $profile = oedReadyProfile($contact); // training_location aún null

    $extracted = ['training_location' => 'home'];
    $result = evidenceDetector()->fillGaps($extracted, 'Entreno en gimnasio con peso libre', $profile);

    expect($result['training_location'])->toBe('home'); // el LLM gana, nunca 'gym'
});

it('B2: el perfil ya tiene un valor persistido — el detector nunca interviene, ni siquiera con el mismo valor', function () {
    $contact = Contact::factory()->create();
    $profile = oedReadyProfile($contact, ['training_location' => TrainingLocation::Gym]);

    $extracted = ['training_location' => null];
    $result = evidenceDetector()->fillGaps($extracted, 'Entreno en gimnasio con peso libre', $profile);

    expect($result['training_location'])->toBeNull(); // no se "re-detecta" ni se toca
});

it('B3: ambos null + evidencia inequívoca — el detector llena el hueco', function () {
    $contact = Contact::factory()->create();
    $profile = oedReadyProfile($contact); // training_location null

    $extracted = ['training_location' => null];
    $result = evidenceDetector()->fillGaps($extracted, 'Entreno en gimnasio con peso libre y máquinas', $profile);

    expect($result['training_location'])->toBe('gym');
});

it('B4: ambos null + sin evidencia — permanece null, sin cambios', function () {
    $contact = Contact::factory()->create();
    $profile = oedReadyProfile($contact);

    $extracted = ['training_location' => null];
    $result = evidenceDetector()->fillGaps($extracted, 'Quiero ganar músculo', $profile);

    expect($result['training_location'])->toBeNull();
});

it('B5: fillGaps() nunca toca ninguna otra clave de extracted — Fase 1 es exclusivamente training_location', function () {
    $contact = Contact::factory()->create();
    $profile = oedReadyProfile($contact);

    $extracted = ['training_location' => null, 'sessions_per_week' => null, 'goal' => null];
    $result = evidenceDetector()->fillGaps($extracted, 'Entreno en gimnasio, 4 días a la semana, quiero ganar músculo', $profile);

    expect($result['training_location'])->toBe('gym'); // única clave afectada
    expect($result['sessions_per_week'])->toBeNull(); // sin backstop en Fase 1
    expect($result['goal'])->toBeNull(); // sin backstop en Fase 1
});

// ============================================================
// C. INTEGRACIÓN REAL (vía TrainingHandler, mismos helpers de
// TrainingConversationFlowTest.php)
// ============================================================

it('C1: regresión exacta del bug reportado — el LLM falla en extraer training_location, el backstop lo persiste y NO se vuelve a preguntar', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    oedReadyProfile($contact); // name/goal/experience_level satisfechos, training_location pendiente
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        // El LLM NO extrae training_location este turno (simula el fallo real);
        // el texto de respuesta simulado es irrelevante — con
        // available_equipment=[] ya presente por defecto (factory), el
        // siguiente requirement bloqueante real después de training_location
        // es health_screening, no equipment: el mismatch entre este
        // next_action simulado y el requirement real solo confirma que
        // resolveQuestion() cae a su propio fallback determinista, sin
        // afectar la aserción central de este test (ver abajo).
        'api.openai.com/v1/chat/completions' => Http::response(
            fakeOnboardingTurn(emptyExtraction(), 'ask_equipment', '¿Qué equipo tienes disponible?'),
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', 'Entreno en gimnasio con peso libre y máquinas. Me gustaría entrenar 4 días a la semana divididos por grupos musculares');

    expect(Contact::find($contact->id)->trainingProfile->fresh()->training_location)->toBe(TrainingLocation::Gym);

    // La siguiente pregunta NUNCA vuelve a ser sobre ubicación — confirma
    // que firstPendingBlocking() ya no ve training_location como faltante,
    // sin importar cuál sea el siguiente requirement real pendiente.
    $bodies = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body', ''));
    expect($bodies->contains(fn ($b) => str_contains($b, '¿Dónde vas a entrenar')))->toBeFalse();
});

it('C2: múltiples campos en el mismo turno — el LLM extrae sessions_per_week, el backstop complementa training_location, ambos persisten', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112234', 'customer_name' => 'Ana']);
    oedReadyProfile($contact);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            fakeOnboardingTurn(
                emptyExtraction(['sessions_per_week' => 4]), // LLM SÍ extrae esto, pero no training_location
                'ask_equipment',
                '¿Qué equipo tienes?'
            ),
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112234', 'Entreno en gimnasio con peso libre y máquinas. Me gustaría entrenar 4 días a la semana');

    $fresh = Contact::find($contact->id)->trainingProfile->fresh();
    expect($fresh->training_location)->toBe(TrainingLocation::Gym); // backstop
    expect($fresh->sessions_per_week)->toBe(4); // LLM, camino existente sin cambios
});

it('C3: Safety escalado (segundo chequeo, sobre safety_signal_text extraído por el LLM) impide por completo que el backstop se ejecute', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112235', 'customer_name' => 'Ana']);
    oedReadyProfile($contact);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        // El cuerpo crudo del mensaje NO contiene ninguna frase de
        // SafetySignalDetector — el primer chequeo (sobre $body, antes de
        // entrar al bloque de onboarding) no escala. Es el LLM quien
        // extrae `safety_signal_text` a partir de contexto conversacional
        // más amplio — esto ejercita específicamente el SEGUNDO chequeo
        // (TrainingHandler.php, justo antes de fillGaps()), que es la
        // posición exacta de integración aprobada.
        'api.openai.com/v1/chat/completions' => Http::response(
            fakeOnboardingTurn(
                emptyExtraction(['safety_signal_text' => 'dolor de pecho']),
                null,
                null
            ),
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112235', 'Entreno en gimnasio, pero tengo un tema de salud que prefiero contarte con calma');

    // El turno se cortó en el segundo chequeo de Safety — training_location
    // nunca se persistió, pese a que el texto contenía evidencia inequívoca
    // ("Entreno en gimnasio"), porque fillGaps() nunca llegó a ejecutarse.
    expect(Contact::find($contact->id)->trainingProfile->fresh()->training_location)->toBeNull();
    expect(Contact::find($contact->id)->trainingProfile->fresh()->isFlaggedForSafetyReview())->toBeTrue();
});

it('C4: una molestia ordinaria (sin escalar Safety) permite que el backstop actúe con normalidad en el mismo mensaje', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112236', 'customer_name' => 'Ana']);
    oedReadyProfile($contact);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        // "me duele la rodilla" es una molestia ordinaria — el LLM la
        // clasifica en restrictions, NUNCA en safety_signal_text (per su
        // propio prompt) — el turno continúa con normalidad.
        'api.openai.com/v1/chat/completions' => Http::response(
            fakeOnboardingTurn(
                emptyExtraction(['restrictions' => ['dolor en la rodilla']]),
                'ask_equipment',
                '¿Qué equipo tienes?'
            ),
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112236', 'me duele la rodilla y entreno en gimnasio');

    $fresh = Contact::find($contact->id)->trainingProfile->fresh();
    expect($fresh->training_location)->toBe(TrainingLocation::Gym); // backstop actuó con normalidad
    expect($fresh->isFlaggedForSafetyReview())->toBeFalse();
});

it('C5: corrección explícita vía LLM sigue funcionando sin cambios — el backstop nunca interfiere cuando el LLM sí extrae', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112237', 'customer_name' => 'Ana']);
    oedReadyProfile($contact, ['training_location' => TrainingLocation::Gym]); // ya tenía un valor
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        // El LLM SÍ extrae la corrección correctamente este turno.
        'api.openai.com/v1/chat/completions' => Http::response(
            fakeOnboardingTurn(emptyExtraction(['training_location' => 'home']), 'ask_equipment', 'Listo, anotado.'),
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112237', 'Ahora entreno en casa');

    expect(Contact::find($contact->id)->trainingProfile->fresh()->training_location)->toBe(TrainingLocation::Home);
});

it('C6: corrección con el LLM fallando (null) NO es realizada por el backstop — limitación aceptada de esta Fase 1', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112238', 'customer_name' => 'Ana']);
    oedReadyProfile($contact, ['training_location' => TrainingLocation::Gym]); // ya tenía un valor persistido
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        // El LLM falla en extraer la corrección este turno.
        'api.openai.com/v1/chat/completions' => Http::response(
            fakeOnboardingTurn(emptyExtraction(), 'ask_equipment', '¿Qué equipo tienes?'),
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112238', 'Ahora entreno en casa');

    // El backstop NUNCA sobrescribe un valor ya persistido (regla gap-only)
    // — el perfil conserva 'gym', pese a que el texto sugiere 'home'. Este
    // es el comportamiento diseñado y aceptado explícitamente (Sección F,
    // Riesgos del diseño aprobado): las correcciones siguen siendo
    // responsabilidad exclusiva del LLM en esta Fase 1.
    expect(Contact::find($contact->id)->trainingProfile->fresh()->training_location)->toBe(TrainingLocation::Gym);
});

// ============================================================
// D. ARQUITECTURA / AISLAMIENTO (mismo patrón "grep del código fuente
// real" que OnboardingRequirementArchitectureTest.php)
// ============================================================

it('D1: OnboardingEvidenceDetector never imports anything from the Safety/Health domain', function () {
    // Se busca un `use` real, no la mención en prosa dentro del propio
    // docblock de la clase explicando la garantía de aislamiento (el
    // docblock aprobado menciona textualmente estos nombres entre backticks
    // como parte de esa explicación — mismo patrón de falso positivo ya
    // documentado en OnboardingRequirementArchitectureTest.php, tests "R"/"C"/"J").
    $source = file_get_contents(app_path('Training/Support/OnboardingEvidenceDetector.php'));

    foreach ([
        'HealthConditionCategory', 'DeclaredHealthCondition', 'SafetySignalDetector',
        'TrainingAccess', 'SafetyRestrictionResolver', 'HealthScreeningRequirement',
    ] as $forbidden) {
        expect($source)->not->toMatch("/use [A-Za-z\\\\]*{$forbidden};/");
    }
});

it('D2: OnboardingEvidenceDetector never imports Eloquent DB facades, Contact, or any AI service', function () {
    $source = file_get_contents(app_path('Training/Support/OnboardingEvidenceDetector.php'));

    foreach (['use Illuminate\\Support\\Facades\\DB;', 'use App\\Models\\Contact;'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
    foreach (['AiServiceInterface', 'AIServiceFactory'] as $forbidden) {
        expect($source)->not->toMatch("/use [A-Za-z\\\\]*{$forbidden};/");
    }
});

it('D3: detectTrainingLocation() is a pure function — its only parameter is a raw string, no TrainingProfile/Contact/DB access', function () {
    $reflection = new ReflectionMethod(OnboardingEvidenceDetector::class, 'detectTrainingLocation');
    $parameters = $reflection->getParameters();

    expect($parameters)->toHaveCount(1);
    expect((string) $parameters[0]->getType())->toBe('string');
});

it('D4: OnboardingEvidenceDetector has no constructor dependencies — 100% self-contained, no IA/DB services injected', function () {
    $reflection = new ReflectionClass(OnboardingEvidenceDetector::class);
    $constructor = $reflection->getConstructor();

    expect($constructor === null || $constructor->getNumberOfParameters() === 0)->toBeTrue();
});

it('D5: only training_location is affected by fillGaps() in this phase — Equipment/Goal/ExperienceLevel/SessionsPerWeek requirements are never referenced', function () {
    $source = file_get_contents(app_path('Training/Support/OnboardingEvidenceDetector.php'));

    foreach (['EquipmentRequirement', 'GoalRequirement', 'ExperienceLevelRequirement', 'SessionsPerWeekRequirement', 'PrimaryFocusRequirement'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});
