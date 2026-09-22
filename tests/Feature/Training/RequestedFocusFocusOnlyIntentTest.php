<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use Illuminate\Support\Facades\Http;

/**
 * Hito B1.3.1 — cierra el hallazgo del Code Review de B1.3: un usuario
 * RECURRENTE (con historial de WorkoutSession, por lo que
 * TrainingContextualIntentClassifier::hasActiveAccessAwaitingFirstWorkout()
 * ya no lo rescata) escribiendo SOLO "Quiero trabajar espalda" no llegaba a
 * TrainingHandler.
 *
 * Helpers propios de este archivo (prefijo "rfFocusOnly"), NUNCA los
 * rfWiring*() de RequestedFocusConversationalWiringTest.php — Pest no
 * garantiza que las funciones globales de un archivo estén disponibles al
 * ejecutar otro archivo de forma aislada (mismo criterio ya documentado en
 * el resto de esta suite: un archivo, sus propios helpers con prefijo
 * único). El fixture principal (rfRecurringUserContact()) es
 * deliberadamente distinto del rfWiringContact() de aquel archivo: crea un
 * contacto CON historial de WorkoutSession, el caso que
 * hasActiveAccessAwaitingFirstWorkout() ya no rescata, y por eso nunca
 * habría detectado esta brecha real.
 */
function rfFocusOnlyExercise(?MuscleFocus $primaryMuscle, string $muscleGroup = 'core'): Exercise
{
    return Exercise::factory()->create([
        'muscle_group' => $muscleGroup,
        'primary_muscle' => $primaryMuscle,
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ]);
}

function rfFocusOnlyCoachResponse(array $intents, array $requestedFocusTerms = []): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'safety_signal_text' => null,
        'intents' => $intents,
        'training_reply' => null,
        'requested_focus_terms' => $requestedFocusTerms,
    ])]]]];
}

function rfFocusOnlySendMessage(Tenant $tenant, string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, '573001112233', $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

function rfRecurringUserContact(array $profileOverrides = []): Contact
{
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);

    TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'health_screening_asked' => true,
        'split_type' => SplitType::FullBody,
        'goal' => TrainingGoal::GeneralFitness,
        'experience_level' => ExperienceLevel::Beginner,
        'available_equipment' => [],
    ], $profileOverrides));

    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    // Historial real: al menos 1 WorkoutSession ya Completed — esto es
    // EXACTAMENTE lo que neutraliza hasActiveAccessAwaitingFirstWorkout()
    // (exige cero WorkoutSession) y por lo tanto lo que expone el hallazgo
    // real del Code Review. Sin sesión pendiente (Scheduled) — precondición
    // explícita del fixture pedido.
    WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);

    // Ningún Reminder esperando respuesta — precondición explícita (el
    // factory por defecto no crea ninguno; se deja constancia aquí para
    // que la intención del fixture sea explícita, no accidental).
    expect(\App\Models\Reminder::where('contact_id', $contact->id)->exists())->toBeFalse();
    expect(WorkoutSession::where('contact_id', $contact->id)->where('status', \App\Training\Enums\WorkoutSessionStatus::Scheduled)->exists())->toBeFalse();

    return $contact->fresh();
}

// ── Test obligatorio de usuario recurrente — los 10 puntos exigidos ──

it('B1.3.1: a recurring user ("Quiero trabajar espalda") reaches TrainingHandler and produces the back group end-to-end', function () {
    $contact = rfRecurringUserContact(['primary_focus' => ['chest'], 'secondary_focus' => null]);
    rfFocusOnlyExercise(MuscleFocus::Back, 'back');
    $profileBefore = $contact->trainingProfile()->first();

    Http::fake([
        // 3. CoachService devuelve requested_focus_terms=["espalda"].
        'api.openai.com/v1/chat/completions' => Http::response(
            rfFocusOnlyCoachResponse(['continue_training'], ['espalda']), 200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    // 1/2. Router reconoce Training (vía el nuevo containsFocusRequest(),
    // NO vía hasActiveAccessAwaitingFirstWorkout() — este contacto YA tiene
    // una WorkoutSession Completed) y llega a TrainingHandler — verificado
    // indirectamente: si el Router no lo hubiera clasificado como Training,
    // ninguna llamada a openai/graph habría ocurrido y no existiría una
    // segunda WorkoutSession (la Completed original seguiría siendo la única).
    rfFocusOnlySendMessage($contact->tenant, 'Quiero trabajar espalda');

    // 6. TrainingEngine crea la NUEVA sesión (además de la Completed histórica).
    $sessions = WorkoutSession::where('contact_id', $contact->id)->orderBy('id')->get();
    expect($sessions)->toHaveCount(2);
    $newSession = $sessions->last();
    expect($newSession->status)->toBe(\App\Training\Enums\WorkoutSessionStatus::Scheduled);

    // 4/5. ConversationTurnResolver generó DeliverSession y
    // RequestedFocusTermMapper produjo el grupo "back" — verificado vía el
    // snapshot, la única evidencia observable end-to-end de ambos pasos.
    $snapshot = $newSession->prescription_context_snapshot;

    // 7. snapshot requested_focus contiene back.
    expect($snapshot['requested_focus'])->toBe([['key' => 'back', 'muscles' => ['back']]]);

    // 8/9. primary_focus/secondary_focus no cambian.
    $profileAfter = $contact->trainingProfile()->first()->fresh();
    expect($profileAfter->primary_focus)->toBe($profileBefore->primary_focus);
    expect($profileAfter->secondary_focus)->toBe($profileBefore->secondary_focus);

    // 10. next_focus sigue derivándose de autonomousFocus (full_body -> única
    // entrada de rotación, nunca "back").
    expect($profileAfter->next_focus)->toBe('arms,back,chest,core,legs,shoulders');
    expect($snapshot['decided_focus'])->toBe('arms,back,chest,core,legs,shoulders');
});

// ── Casos positivos obligatorios (usuario recurrente) ──

it('positive 1: "Quiero trabajar espalda" -> Training', function () {
    $contact = rfRecurringUserContact();
    rfFocusOnlyExercise(MuscleFocus::Back, 'back');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(rfFocusOnlyCoachResponse(['continue_training'], ['espalda']), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Quiero trabajar espalda');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(2);
});

it('positive 2: "Quiero entrenar pecho" -> Training (ya cubierto por la keyword existente "entrenar", confirmado sin regresión)', function () {
    $contact = rfRecurringUserContact();
    rfFocusOnlyExercise(MuscleFocus::Chest, 'chest');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(rfFocusOnlyCoachResponse(['continue_training'], ['pecho']), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Quiero entrenar pecho');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(2);
});

it('positive 3: "Hoy quiero trabajar piernas" -> Training', function () {
    $contact = rfRecurringUserContact();
    rfFocusOnlyExercise(MuscleFocus::Quads, 'core');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(rfFocusOnlyCoachResponse(['continue_training'], ['piernas']), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Hoy quiero trabajar piernas');

    $session = WorkoutSession::where('contact_id', $contact->id)->orderBy('id')->get()->last();
    expect($session->prescription_context_snapshot['requested_focus'])->toBe([
        ['key' => 'legs', 'muscles' => ['quads', 'hamstrings', 'glutes', 'calves']],
    ]);
});

it('positive 4: "Quiero hacer brazos" -> Training', function () {
    $contact = rfRecurringUserContact();
    rfFocusOnlyExercise(MuscleFocus::Biceps, 'arms');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(rfFocusOnlyCoachResponse(['continue_training'], ['brazos']), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Quiero hacer brazos');

    $session = WorkoutSession::where('contact_id', $contact->id)->orderBy('id')->get()->last();
    expect($session->prescription_context_snapshot['requested_focus'])->toBe([
        ['key' => 'arms', 'muscles' => ['biceps', 'triceps']],
    ]);
});

it('positive 5: "Quiero mi rutina y trabajar pecho" sigue funcionando como antes (vía la keyword "rutina" ya existente)', function () {
    $contact = rfRecurringUserContact();
    rfFocusOnlyExercise(MuscleFocus::Chest, 'chest');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(rfFocusOnlyCoachResponse(['continue_training'], ['pecho']), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Quiero mi rutina y trabajar pecho');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(2);
});

it('positive 6 (Code Review final, gap): "Vamos con pecho" -> Training', function () {
    $contact = rfRecurringUserContact();
    rfFocusOnlyExercise(MuscleFocus::Chest, 'chest');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(rfFocusOnlyCoachResponse(['continue_training'], ['pecho']), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Vamos con pecho');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(2);
});

it('positive 7 (Code Review final, gap): "Me toca espalda" -> Training', function () {
    $contact = rfRecurringUserContact();
    rfFocusOnlyExercise(MuscleFocus::Back, 'back');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(rfFocusOnlyCoachResponse(['continue_training'], ['espalda']), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Me toca espalda');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(2);
});

it('positive 8 (Code Review final, gap NC-4): "Quiero trabajar pecho y piernas" -> Training, requested_focus contiene chest + legs', function () {
    $contact = rfRecurringUserContact();
    rfFocusOnlyExercise(MuscleFocus::Chest, 'chest');
    rfFocusOnlyExercise(MuscleFocus::Quads, 'core');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(rfFocusOnlyCoachResponse(['continue_training'], ['pecho', 'piernas']), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Quiero trabajar pecho y piernas');

    $session = WorkoutSession::where('contact_id', $contact->id)->orderBy('id')->get()->last();
    expect($session->prescription_context_snapshot['requested_focus'])->toBe([
        ['key' => 'chest', 'muscles' => ['chest']],
        ['key' => 'legs', 'muscles' => ['quads', 'hamstrings', 'glutes', 'calves']],
    ]);
});

// ── Casos negativos obligatorios — la nueva lógica focus-only NO debe generar DeliverSession ──
//
// Deliberadamente NO se afirma nada sobre a qué handler cae cada mensaje
// (Router/FallbackChatHandler/CustomerCare son responsabilidad de reglas ya
// existentes, sin cambios) — lo único que la nueva lógica focus-only debe
// garantizar es que NUNCA produzca un DeliverSession, es decir, que NUNCA
// aparezca una WorkoutSession nueva además de la histórica ya sembrada por
// rfRecurringUserContact(). No se fakea openai a propósito: si algún otro
// camino (ej. FallbackChatHandler) lo invoca por su cuenta, Http::fake()
// sin esa ruta configurada le devuelve una respuesta vacía inerte — no
// afecta la aserción real de este archivo.

it('negative 1: "Me duele la espalda" nunca genera una WorkoutSession nueva', function () {
    $contact = rfRecurringUserContact();
    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Me duele la espalda');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
});

it('negative 2: "Tengo dolor en el hombro" nunca genera una WorkoutSession nueva', function () {
    $contact = rfRecurringUserContact();
    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Tengo dolor en el hombro');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
});

it('negative 3: "Me lesioné la pierna" nunca genera una WorkoutSession nueva', function () {
    $contact = rfRecurringUserContact();
    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Me lesioné la pierna');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
});

it('negative 4: "Me molesta el pecho" nunca genera una WorkoutSession nueva', function () {
    $contact = rfRecurringUserContact();
    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Me molesta el pecho');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
});

it('negative 5: "¿Qué músculos tiene la espalda?" nunca genera una WorkoutSession nueva', function () {
    $contact = rfRecurringUserContact();
    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, '¿Qué músculos tiene la espalda?');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
});

it('negative 6: "Mi hermano entrena piernas" nunca genera una WorkoutSession nueva', function () {
    $contact = rfRecurringUserContact();
    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfFocusOnlySendMessage($contact->tenant, 'Mi hermano entrena piernas');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
});

// ── Unit-level directo sobre el classifier (aísla la regla de la integración completa) ──

it('TrainingIntentClassifier: containsFocusRequest requires BOTH explicit request language AND a recognized term', function () {
    $classifier = new \App\Training\Support\TrainingIntentClassifier;
    $tenant = Tenant::factory()->create();
    $ctx = fn (string $body) => new \App\Core\Messaging\ExecutionContext(
        tenant: $tenant, conversation: null,
        message: new \App\Core\Messaging\IngestedMessage('573001112233', $body, 'wamid.1', 'text', null),
    );

    // Positivos.
    expect($classifier->classify($ctx('Quiero trabajar espalda')))->toBe(\App\Core\Messaging\Intent::Training);
    expect($classifier->classify($ctx('Hoy quiero trabajar piernas')))->toBe(\App\Core\Messaging\Intent::Training);
    expect($classifier->classify($ctx('Quiero hacer brazos')))->toBe(\App\Core\Messaging\Intent::Training);
    expect($classifier->classify($ctx('Me toca pecho hoy')))->toBe(\App\Core\Messaging\Intent::Training);
    expect($classifier->classify($ctx('Vamos con piernas')))->toBe(\App\Core\Messaging\Intent::Training);

    // Negativos — trigger de petición SIN término reconocido.
    expect($classifier->classify($ctx('Quiero trabajar duro hoy')))->toBeNull();
    // Negativos — término reconocido SIN trigger de petición.
    expect($classifier->classify($ctx('Me duele la espalda')))->toBeNull();
    expect($classifier->classify($ctx('Tengo dolor en el hombro')))->toBeNull();
    expect($classifier->classify($ctx('Me lesioné la pierna')))->toBeNull();
    expect($classifier->classify($ctx('Me molesta el pecho')))->toBeNull();
    expect($classifier->classify($ctx('¿Qué músculos tiene la espalda?')))->toBeNull();
    expect($classifier->classify($ctx('Mi hermano entrena piernas')))->toBeNull();
});

it('TrainingIntentClassifier B1.3.1.1: CRITICAL-1 casos 6-14 del Code Review final', function () {
    $classifier = new \App\Training\Support\TrainingIntentClassifier;
    $tenant = Tenant::factory()->create();
    $ctx = fn (string $body) => new \App\Core\Messaging\ExecutionContext(
        tenant: $tenant, conversation: null,
        message: new \App\Core\Messaging\IngestedMessage('573001112233', $body, 'wamid.1', 'text', null),
    );

    // 6-9: conjugación/tercera persona/pregunta — ya excluidos por ausencia
    // de trigger en primera persona (sin cambios de esta ronda, reconfirmado).
    expect($classifier->classify($ctx('Mi hermano quiere trabajar espalda')))->toBeNull();
    expect($classifier->classify($ctx('¿Quieres trabajar espalda?')))->toBeNull();
    expect($classifier->classify($ctx('¿Qué toca para espalda?')))->toBeNull();
    expect($classifier->classify($ctx('Me dijeron que trabaje espalda')))->toBeNull();

    // 10-11: dolor/lesión solos, sin trigger — ya excluidos (sin cambios).
    expect($classifier->classify($ctx('Me duele la espalda')))->toBeNull();
    expect($classifier->classify($ctx('Me lesioné la pierna')))->toBeNull();

    // 12-13: CRITICAL-1 — trigger + término reconocido + marcador de
    // exclusión conservadora en la MISMA frase. Antes de esta ronda,
    // containsFocusRequest() clasificaba ambos como Intent::Training
    // (verificado y reportado en el Code Review). Ahora deben ser null.
    expect($classifier->classify($ctx('Quiero trabajar espalda pero me duele')))->toBeNull();
    expect($classifier->classify($ctx('Quiero trabajar piernas porque me lesioné')))->toBeNull();

    // 14: regresión documentada — permanece Training, pero vía la KEYWORD
    // legacy "quiero entrenar" (ya en KEYWORDS desde antes de B1.3), NUNCA
    // vía containsFocusRequest(): classify() retorna en el bucle de
    // KEYWORDS antes de llegar a evaluar la exclusión conservadora, así
    // que CONSERVATIVE_EXCLUSION_MARKERS nunca se evalúa para este mensaje
    // — riesgo preexistente de SafetySignalDetector, fuera de alcance de
    // este hito (ver diseño aprobado B1.3.1.1, Sección 3, caso 9).
    expect($classifier->classify($ctx('Quiero entrenar pecho, aunque me duele')))->toBe(\App\Core\Messaging\Intent::Training);
});
