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
 * Hito B1.3 (Requested Focus — wiring conversacional) — E2E real:
 * WhatsApp -> Router -> TrainingHandler -> CoachService (IA, único punto de
 * extracción) -> ConversationTurnResolver -> RequestedFocusTermMapper ->
 * TrainingEngine::decideNextSession($contact, $requestedFocus) -> WorkoutSession.
 *
 * Mismo patrón que TrainingConversationFlowTest.php (Job real, Http::fake
 * en vez de mockear TrainingHandler) — prueba el WIRING real, no solo la
 * lógica de dominio de B1 (ya cubierta en RequestedFocus*Test.php).
 *
 * Nombres de helper con prefijo "rfWiring" para evitar colisión de
 * funciones globales con TrainingConversationFlowTest.php y el resto de la
 * suite (mismo criterio ya documentado en otros archivos de test de este
 * repositorio).
 */
function rfWiringContact(array $profileOverrides = []): Contact
{
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);

    TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'health_screening_asked' => true, // onboarding completo -> CoachService es la única llamada de IA del turno
        'split_type' => SplitType::FullBody,
        'goal' => TrainingGoal::GeneralFitness,
        'experience_level' => ExperienceLevel::Beginner,
        'available_equipment' => [],
    ], $profileOverrides));

    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

function rfWiringExercise(?MuscleFocus $primaryMuscle, string $muscleGroup = 'core', array $overrides = []): Exercise
{
    return Exercise::factory()->create(array_merge([
        'muscle_group' => $muscleGroup,
        'primary_muscle' => $primaryMuscle,
        'difficulty_level' => 'beginner',
        'equipment_needed' => [],
    ], $overrides));
}

function rfWiringCoachResponse(array $intents, ?array $requestedFocusTerms = null, ?string $safetySignalText = null): array
{
    $payload = [
        'safety_signal_text' => $safetySignalText,
        'intents' => $intents,
        'training_reply' => null,
    ];

    if ($requestedFocusTerms !== null) {
        $payload['requested_focus_terms'] = $requestedFocusTerms;
    }

    return ['choices' => [['message' => ['content' => json_encode($payload)]]]];
}

function rfWiringSendMessage(Tenant $tenant, string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, '573001112233', $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

function rfWiringSnapshot(Contact $contact): array
{
    return WorkoutSession::where('contact_id', $contact->id)->sole()->prescription_context_snapshot;
}

function rfWiringCoverageKeys(array $snapshot): array
{
    return array_column($snapshot['requested_focus_coverage'], 'key');
}

// ── 1: "Quiero mi rutina" -> requestedFocus=null, camino legacy ──

it('1: "quiero mi rutina" produces requestedFocus=null and the legacy selection path', function () {
    $contact = rfWiringContact();
    rfWiringExercise(null, 'chest');

    Http::fake([
        // Sin la clave "requested_focus_terms" en absoluto -> prueba el
        // default de CoachService::parseJson() (nunca se omite ni null).
        'api.openai.com/v1/chat/completions' => Http::response(rfWiringCoachResponse(['continue_training']), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfWiringSendMessage($contact->tenant, 'Quiero mi rutina');

    $snapshot = rfWiringSnapshot($contact);
    expect($snapshot['requested_focus'])->toBe([]);
    expect($snapshot['requested_focus_coverage'])->toBe([]);
});

// ── 2: E2E principal — "pecho y piernas" ──

it('2 (E2E principal): "quiero mi rutina y quiero trabajar pecho y piernas" atraviesa el wiring real de punta a punta', function () {
    $contact = rfWiringContact(['primary_focus' => ['back'], 'secondary_focus' => null]);
    $chest = rfWiringExercise(MuscleFocus::Chest, 'chest');
    $quads = rfWiringExercise(MuscleFocus::Quads, 'core');
    $profileBefore = $contact->trainingProfile()->first();

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            rfWiringCoachResponse(['continue_training'], ['pecho', 'piernas']), 200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfWiringSendMessage($contact->tenant, 'Quiero mi rutina y quiero trabajar pecho y piernas');

    // 6. WorkoutSession se crea.
    $session = WorkoutSession::where('contact_id', $contact->id)->sole();
    $snapshot = $session->prescription_context_snapshot;

    // 7/8. snapshot contiene requested_focus y requested_focus_coverage.
    expect($snapshot['requested_focus'])->toBe([
        ['key' => 'chest', 'muscles' => ['chest']],
        ['key' => 'legs', 'muscles' => ['quads', 'hamstrings', 'glutes', 'calves']],
    ]);
    expect(rfWiringCoverageKeys($snapshot))->toBe(['chest', 'legs']);

    // 9. selected exercises cumplen la lógica de B1 (grupos, no un tier plano).
    $mainIds = $session->workoutExercises->pluck('exercise_id');
    expect($mainIds)->toContain($chest->id, $quads->id);

    // 10/11. TrainingProfile no cambia.
    $profileAfter = $contact->trainingProfile()->first()->fresh();
    expect($profileAfter->primary_focus)->toBe($profileBefore->primary_focus);
    expect($profileAfter->secondary_focus)->toBe($profileBefore->secondary_focus);

    // 12. next_focus no cambia como consecuencia de requested focus — con
    // split_type=FullBody, ROTATIONS tiene una sola entrada: next_focus
    // siempre es exactamente esa cadena, nunca "chest"/"legs".
    expect($profileAfter->next_focus)->toBe('arms,back,chest,core,legs,shoulders');
    expect($snapshot['decided_focus'])->toBe('arms,back,chest,core,legs,shoulders');
});

it('"quiero mi rutina" (sin foco) sigue el camino legacy — comparación directa con el caso anterior', function () {
    $contact = rfWiringContact();
    rfWiringExercise(null, 'chest');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(rfWiringCoachResponse(['continue_training'], []), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfWiringSendMessage($contact->tenant, 'Quiero mi rutina');

    $session = WorkoutSession::where('contact_id', $contact->id)->sole();
    expect($session->workoutExercises->count())->toBeGreaterThan(0);
    expect($session->prescription_context_snapshot['requested_focus'])->toBe([]);
});

// ── 3: "Quiero trabajar espalda" -> grupo back ──

it('3: "quiero trabajar espalda" produce el grupo back', function () {
    $contact = rfWiringContact();
    rfWiringExercise(MuscleFocus::Back, 'back');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            rfWiringCoachResponse(['continue_training'], ['espalda']), 200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfWiringSendMessage($contact->tenant, 'Quiero trabajar espalda');

    $snapshot = rfWiringSnapshot($contact);
    expect($snapshot['requested_focus'])->toBe([['key' => 'back', 'muscles' => ['back']]]);
});

// ── 4: "Quiero pecho y brazos" -> chest + arms(biceps+triceps) ──

it('4: "quiero pecho y brazos" produce chest y arms(biceps+triceps)', function () {
    $contact = rfWiringContact();
    rfWiringExercise(MuscleFocus::Chest, 'chest');
    rfWiringExercise(MuscleFocus::Biceps, 'arms');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            rfWiringCoachResponse(['continue_training'], ['pecho', 'brazos']), 200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfWiringSendMessage($contact->tenant, 'Quiero pecho y brazos');

    $snapshot = rfWiringSnapshot($contact);
    expect($snapshot['requested_focus'])->toBe([
        ['key' => 'chest', 'muscles' => ['chest']],
        ['key' => 'arms', 'muscles' => ['biceps', 'triceps']],
    ]);
});

// ── 5: "Quiero todo el cuerpo" -> ausencia de requested focus ──

it('5: "quiero todo el cuerpo" -> ausencia de requested focus, incluso si la IA lo extrae como término literal', function () {
    $contact = rfWiringContact();
    rfWiringExercise(null, 'chest');

    Http::fake([
        // La IA puede extraer "todo el cuerpo" literalmente (el prompt no se
        // lo prohíbe) — es RequestedFocusTermMapper, nunca la IA, quien
        // decide que esto representa ausencia de foco.
        'api.openai.com/v1/chat/completions' => Http::response(
            rfWiringCoachResponse(['continue_training'], ['todo el cuerpo']), 200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfWiringSendMessage($contact->tenant, 'Quiero todo el cuerpo');

    $snapshot = rfWiringSnapshot($contact);
    expect($snapshot['requested_focus'])->toBe([]);
    expect($snapshot['requested_focus_coverage'])->toBe([]);
});

// ── 6: término desconocido -> nunca un MuscleFocus arbitrario, nunca selección arbitraria ──

it('6: un término desconocido nunca produce un MuscleFocus arbitrario ni una selección arbitraria', function () {
    $contact = rfWiringContact();
    rfWiringExercise(null, 'chest');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            rfWiringCoachResponse(['continue_training'], ['bíceps femoral inventado']), 200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfWiringSendMessage($contact->tenant, 'Quiero trabajar bíceps femoral inventado');

    $snapshot = rfWiringSnapshot($contact);
    // Término no reconocido -> ningún grupo -> requestedFocus=null -> camino legacy.
    expect($snapshot['requested_focus'])->toBe([]);
    expect($snapshot['requested_focus_coverage'])->toBe([]);
});

// ── 7: requested focus + señal de seguridad -> Safety retiene precedencia ──

it('7: una señal de seguridad en el mismo mensaje impide por completo la generación de sesión, incluso con requested focus', function () {
    $contact = rfWiringContact();
    rfWiringExercise(MuscleFocus::Chest, 'chest');

    // Nunca se llega a invocar a CoachService: SafetySignalDetector corre
    // sobre el texto CRUDO en el paso 1 de TrainingHandler::handle(), antes
    // de cualquier otra cosa — ni siquiera hace falta Http::fake() para
    // openai (nunca se llama).
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    rfWiringSendMessage($contact->tenant, 'Quiero trabajar pecho y piernas hoy, pero tengo mucho dolor en el pecho');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(0);
    expect($contact->trainingProfile()->first()->fresh()->safety_status)
        ->toBe(\App\Training\Enums\SafetyStatus::FlaggedForReview);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'seguridad'));
});

// ── 8: requested focus no modifica primary_focus/secondary_focus/next_focus ──

it('8: requested focus no modifica primary_focus, secondary_focus ni next_focus', function () {
    $contact = rfWiringContact(['primary_focus' => ['back'], 'secondary_focus' => ['shoulders'], 'next_focus' => null]);
    rfWiringExercise(MuscleFocus::Chest, 'chest');
    rfWiringExercise(MuscleFocus::Quads, 'core');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            rfWiringCoachResponse(['continue_training'], ['pecho', 'piernas']), 200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfWiringSendMessage($contact->tenant, 'Quiero mi rutina y quiero trabajar pecho y piernas');

    $profileAfter = $contact->trainingProfile()->first()->fresh();
    expect($profileAfter->primary_focus)->toBe(['back']);
    expect($profileAfter->secondary_focus)->toBe(['shoulders']);
    expect($profileAfter->next_focus)->toBe('arms,back,chest,core,legs,shoulders'); // autonomousFocus, full_body -> única entrada
});

// ── 9/10: requested_focus y requested_focus_coverage aparecen correctamente en el snapshot ──

it('9-10: requested_focus y requested_focus_coverage aparecen correctamente en el snapshot, con status fulfilled cuando el catálogo alcanza', function () {
    $contact = rfWiringContact();
    // 3: cubre exactamente el N=3 por defecto (30min/general_fitness) para
    // un único grupo (theoretical=3) -> status 'fulfilled' de verdad.
    for ($i = 0; $i < 3; $i++) {
        rfWiringExercise(MuscleFocus::Chest, 'chest');
    }

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            rfWiringCoachResponse(['continue_training'], ['pecho']), 200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfWiringSendMessage($contact->tenant, 'Quiero trabajar pecho');

    $snapshot = rfWiringSnapshot($contact);
    expect($snapshot['requested_focus'])->toBe([['key' => 'chest', 'muscles' => ['chest']]]);
    expect($snapshot['requested_focus_coverage'])->toHaveCount(1);
    expect($snapshot['requested_focus_coverage'][0]['key'])->toBe('chest');
    expect($snapshot['requested_focus_coverage'][0]['status'])->toBe('fulfilled');
});

// ── 11: decided_focus sigue representando autonomousFocus ──

it('11: decided_focus en el snapshot sigue siendo el foco autónomo, nunca los términos solicitados', function () {
    $contact = rfWiringContact();
    rfWiringExercise(MuscleFocus::Chest, 'chest');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            rfWiringCoachResponse(['continue_training'], ['pecho']), 200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfWiringSendMessage($contact->tenant, 'Quiero trabajar pecho');

    $snapshot = rfWiringSnapshot($contact);
    expect($snapshot['decided_focus'])->not->toBe('chest');
    expect($snapshot['decided_focus'])->toBe('arms,back,chest,core,legs,shoulders');
});

// ── 12: el flujo legacy permanece sin cambios cuando no se solicita foco ──

it('12: el flujo legacy de entrega (mensaje de intro + primer ejercicio) permanece sin cambios cuando no hay requested focus', function () {
    $contact = rfWiringContact(['experience_level' => ExperienceLevel::Intermediate]);
    rfWiringExercise(null, 'chest', ['name' => 'Flexiones', 'difficulty_level' => 'intermediate', 'video_url' => 'https://videos.example.test/pushup.mp4']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(rfWiringCoachResponse(['continue_training']), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    rfWiringSendMessage($contact->tenant, 'Dame mi entrenamiento de hoy');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'entrenamiento de hoy'));
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Flexiones'));
    expect(WorkoutSession::where('contact_id', $contact->id)->sole()->prescription_context_snapshot['requested_focus'])->toBe([]);
});
