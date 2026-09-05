<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Training\Onboarding\OnboardingRequirementRegistry;
use Illuminate\Support\Facades\Http;

/**
 * End-to-end coverage of the first real Training conversational flow
 * (Hito 5, actualizado en Hito 5.1): WhatsApp → Router → TrainingHandler →
 * TrainingEngine → WorkoutSession → respuesta + video. Goes through the
 * real Job, exactly like tests/Feature/ProcessWhatsAppMessageJobTest.php
 * does for FallbackChatHandler — proving the wiring (Router/Dispatcher/
 * AppServiceProvider), not just the Handler in isolation.
 *
 * Hito 5.1: cada turno de onboarding incompleto ahora hace UNA sola
 * llamada de IA (antes eran 2: extract + narrate) — ver
 * App\Training\Support\OnboardingConversationService y D026 en
 * docs/DECISIONS.md. `fakeOnboardingTurn()` refleja el nuevo contrato
 * combinado.
 */

function fakeOnboardingTurn(array $extracted, ?string $nextAction, ?string $response): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'extracted' => $extracted,
        'next_action' => $nextAction,
        'response' => $response,
    ])]]]];
}

function emptyExtraction(array $overrides = []): array
{
    return array_merge([
        'name' => null, 'goal' => null, 'experience_level' => null,
        'primary_focus' => null, 'secondary_focus' => null, 'training_location' => null,
        'restrictions' => null, 'available_equipment' => null, 'equipment_fully_equipped' => null,
        'sessions_per_week' => null, 'age' => null, 'sex' => null, 'weight_kg' => null,
        'height_cm' => null, 'safety_signal_text' => null,
        // Bloque 5 (D048)
        'health_declaration_category' => null, 'health_condition_text' => null, 'functional_limitation_text' => null,
    ], $overrides);
}

function sendTrainingMessage(Tenant $tenant, string $from, ?string $body, ?string $messageType = 'text', ?string $mediaId = null): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), $messageType, $mediaId);
    app()->call([$job, 'handle']);
}

it('routes a training-intent message to TrainingHandler and starts onboarding for a brand-new contact', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        // Hito 8.3: para un Contact recién creado, Contact.customer_name es
        // null y "name" pasa a ser el primer campo obligatorio del onboarding.
        'api.openai.com/v1/chat/completions' => Http::response(
            fakeOnboardingTurn(emptyExtraction(), 'ask_name', '¿Cómo te gustaría que te llame?'),
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', 'Quiero empezar a entrenar');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001112233')->first();
    expect($contact)->not->toBeNull();

    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->isOnboardingComplete($contact))->toBeFalse();
    expect($profile->goal)->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v20.0/'.$tenant->wa_phone_number_id.'/messages'
        && str_contains(data_get($request->data(), 'text.body', ''), '¿Cómo te gustaría que te llame?'));

    // Hito 5.1: UNA sola llamada a la IA por turno (antes eran 2).
    Http::assertSentCount(2); // 1 a la IA + 1 a WhatsApp
});

it('persists progressively answered onboarding fields turn by turn, without re-asking what is already known', function () {
    // Bloque 4 (D047): primary_focus/sessions_per_week/physical_stats ya NO
    // bloquean la primera rutina. Bloque 5 (D048): HealthScreeningRequirement
    // reemplazó a RestrictionsRequirement como último bloqueante — se exige
    // name/goal/experience_level/training_location/available_equipment/
    // health_screening. El onboarding se completa en 2 turnos en este
    // escenario, no en 3.
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    // Nombre ya conocido de antemano — este test se enfoca en la
    // acumulación progresiva del resto de campos, no en la captura del
    // nombre (que tiene su propia cobertura dedicada).
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(fakeOnboardingTurn(
                emptyExtraction(['goal' => 'build_muscle', 'training_location' => 'gym']),
                'ask_experience_level',
                '¿Cuál es tu nivel de experiencia?'
            ))
            ->push(fakeOnboardingTurn(
                emptyExtraction(['experience_level' => 'beginner', 'available_equipment' => [], 'health_condition_text' => '']),
                'complete_onboarding',
                '¡Perfecto, ya tengo todo!'
            )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', 'Quiero ganar músculo, entreno en el gimnasio');

    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile->goal->value)->toBe('build_muscle');
    expect($profile->training_location->value)->toBe('gym');
    expect($profile->experience_level)->toBeNull();

    // Segundo turno: con esto, TODO lo bloqueante queda satisfecho — el
    // onboarding se completa dentro de este mismo turno, sin necesitar una
    // tercera interacción (a diferencia del comportamiento anterior, donde
    // sessions_per_week/physical_stats seguían bloqueando).
    sendTrainingMessage($tenant, '573001112233', 'Soy principiante, sin lesiones, sin equipo');

    $profile->refresh();
    expect(app(OnboardingRequirementRegistry::class)->isOnboardingComplete($profile, $contact))->toBeTrue();
    expect($profile->experience_level->value)->toBe('beginner');
    expect($profile->goal->value)->toBe('build_muscle'); // conservado del primer turno
    expect($profile->sessions_per_week)->toBeNull(); // nunca se preguntó — oportunista, nunca bloqueó
    expect($profile->primary_focus)->toBeNull(); // ídem

    // Sin acceso todavía -> se le informa que debe activar el servicio, con
    // instrucción explícita de cómo hacerlo (Hito 8.1 — el mensaje anterior
    // ("Contáctanos") no invitaba a decir "quiero pagar"). Esto ocurre
    // dentro del MISMO segundo turno, sin AI adicional.
    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v20.0/'.$tenant->wa_phone_number_id.'/messages'
        && str_contains(data_get($request->data(), 'text.body', ''), 'activar tu acceso')
        && str_contains(data_get($request->data(), 'text.body', ''), 'quiero pagar'));

    $aiCalls = 0;
    foreach (Http::recorded() as [$request, $response]) {
        if (str_contains($request->url(), 'api.openai.com')) {
            $aiCalls++;
        }
    }
    expect($aiCalls)->toBe(2); // exactamente 1 por turno, 2 turnos — no un tercero
});

// 11 y 12 (Hito 5.1, ajustado en Bloque 4/D047, luego en Bloque 5/D048):
// flujo completo de onboarding turno a turno + exactamente UNA llamada HTTP
// a IA por cada turno mientras el onboarding BLOQUEANTE sigue incompleto
// (no 2). Desde el Bloque 5, `health_screening` es el último requirement
// bloqueante en el orden de registro (reemplazó a `restrictions`) — el
// onboarding se completa en 6 turnos, no en 8: `sessions_per_week`/
// `primary_focus`/`physical_stats` ya nunca bloquean, así que los turnos 7
// y 8 de este guion no disparan ninguna llamada de IA — el onboarding ya
// terminó y el flujo cae directo al chequeo de acceso.
it('completes the full onboarding conversation turn by turn with exactly ONE AI call per incomplete (blocking) turn', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    // Nombre ya conocido de antemano — el turno de captura de nombre tiene
    // su propia cobertura dedicada (ver primer test de este archivo).
    Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Miguel']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(fakeOnboardingTurn(emptyExtraction(['goal' => 'lose_weight']), 'ask_experience_level', 'Genial, vamos a perder peso. ¿Ya has entrenado antes?'))
            ->push(fakeOnboardingTurn(emptyExtraction(['experience_level' => 'intermediate']), 'ask_training_location', '¿Dónde vas a entrenar?'))
            // Turno 3: el usuario menciona espontáneamente su foco (oportunista,
            // extraído igual gracias a la extracción múltiple existente) sin
            // que eso cambie qué falta bloqueante.
            ->push(fakeOnboardingTurn(emptyExtraction(['primary_focus' => []]), 'ask_training_location', 'Anotado. ¿Dónde vas a entrenar?'))
            ->push(fakeOnboardingTurn(emptyExtraction(['training_location' => 'gym']), 'ask_equipment', '¿Qué equipo tienes?'))
            ->push(fakeOnboardingTurn(emptyExtraction(['available_equipment' => []]), 'ask_health_screening', 'Anotado. ¿Alguna lesión o dolor que deba saber?'))
            // Turno 6: la condición viene con detalle funcional explícito en
            // el MISMO mensaje (caso C) — cierra el screening en 1 turno, sin
            // necesitar una pregunta de seguimiento aparte.
            ->push(fakeOnboardingTurn(emptyExtraction([
                'health_condition_text' => 'dolor en las rodillas',
                'functional_limitation_text' => 'no puedo hacer sentadillas profundas',
            ]), 'complete_onboarding', '¡Listo, ya tengo tu perfil completo!')),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    $turns = ['Quiero perder peso', 'Intermedio', 'Todo por igual', 'Gimnasio', 'Ninguno', 'Dolor en las rodillas, no puedo hacer sentadillas profundas', '3 veces', 'Prefiero no decir'];

    foreach ($turns as $turn) {
        sendTrainingMessage($tenant, '573001112233', $turn);
    }

    $contact = Contact::where('customer_phone', '573001112233')->first();
    $profile = TrainingProfile::where('contact_id', $contact->id)->first();

    expect(app(OnboardingRequirementRegistry::class)->isOnboardingComplete($profile, $contact))->toBeTrue();
    expect($profile->health_screening_asked)->toBeTrue();
    expect($profile->goal->value)->toBe('lose_weight');
    expect($profile->experience_level->value)->toBe('intermediate');
    expect($profile->training_location->value)->toBe('gym');
    expect($profile->primary_focus)->toBe([]); // capturado oportunísticamente en el turno 3
    // Los turnos 7 y 8 ("3 veces", "Prefiero no decir") nunca llegaron a
    // preguntarse — el onboarding bloqueante ya había terminado en el turno
    // 6, así que sessions_per_week/physical_stats se quedan sin responder,
    // sin que eso bloquee nada.
    expect($profile->sessions_per_week)->toBeNull();
    expect($profile->age)->toBeNull();

    // 6 llamadas de IA (una por turno bloqueante incompleto) + 8 respuestas
    // de WhatsApp (una por cada turno enviado, incluidos los 2 finales que
    // ya no llaman a la IA porque el onboarding bloqueante ya terminó).
    Http::assertSentCount(6 + count($turns));

    $aiCalls = 0;
    foreach (Http::recorded() as [$request, $response]) {
        if (str_contains($request->url(), 'api.openai.com')) {
            $aiCalls++;
        }
    }
    expect($aiCalls)->toBe(6);
});

it('informs the user they need to activate the service when access is denied, with an explicit instruction (Hito 8.1)', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    // health_screening_asked: true — Bloque 5 (D048): sin esto, la factory
    // dejaría el screening sin responder y el perfil ya NO se consideraría
    // completo bajo el nuevo Registry.
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]); // complete, no TrainingAccess row

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    sendTrainingMessage($tenant, '573001112233', 'Dame mi entrenamiento de hoy');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(0);
    // Hito 8.1 — hallazgo real del E2E comercial: el mensaje anterior
    // ("Contáctanos para activarlo") era vago; ahora instruye explícitamente
    // qué escribir para activar el acceso.
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'activar tu acceso')
        && str_contains(data_get($request->data(), 'text.body', ''), 'quiero pagar'));
    Http::assertSentCount(1); // no AI call at all — profile already complete
});

it('generates and delivers a WorkoutSession with videos when access is granted, without asking onboarding questions again', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'available_equipment' => [], 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $chest = Exercise::factory()->create([
        'muscle_group' => 'chest', 'name' => 'Flexiones', 'video_url' => 'https://videos.example.test/pushup.mp4',
        'instructions' => ['Manos a la anchura de los hombros', 'Cuerpo alineado'],
        'breathing_cue' => 'Inhala al bajar, exhala al subir',
    ]);
    $legs = Exercise::factory()->create(['muscle_group' => 'legs', 'name' => 'Sentadilla', 'video_url' => 'https://videos.example.test/squat.mp4']);
    $back = Exercise::factory()->create(['muscle_group' => 'back', 'name' => 'Remo', 'video_url' => 'https://videos.example.test/row.mp4']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    sendTrainingMessage($tenant, '573001112233', 'Quiero mi entrenamiento de hoy');

    $session = WorkoutSession::where('contact_id', $contact->id)->first();
    expect($session)->not->toBeNull();
    expect($session->workoutExercises)->toHaveCount(3);

    // La cabecera mínima de sesión se envió.
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'entrenamiento de hoy'));

    // El texto de técnica de cada ejercicio se envió, con sus instrucciones
    // reales tomadas del snapshot — nunca hardcodeadas en TrainingHandler.
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Flexiones')
        && str_contains(data_get($request->data(), 'text.body', ''), 'Manos a la anchura de los hombros')
        && str_contains(data_get($request->data(), 'text.body', ''), 'Inhala al bajar, exhala al subir'));

    // Un video por cada ejercicio con exercise_snapshot.video_url.
    foreach ([$chest, $legs, $back] as $exercise) {
        Http::assertSent(fn ($request) => data_get($request->data(), 'type') === 'video'
            && data_get($request->data(), 'video.link') === $exercise->video_url);
    }

    // Perfil ya completo: cero llamadas al proveedor de IA.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
});

it('blocks generation and escalates immediately when the message contains a safety signal', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    sendTrainingMessage($tenant, '573001112233', 'Quiero entrenar pero tengo un fuerte dolor de pecho');

    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile->isFlaggedForSafetyReview())->toBeTrue();
    expect($profile->safety_flag_reason)->toBe('chest_pain');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(0);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'profesional de la salud'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
});

it('keeps a previously safety-flagged profile blocked on a later turn, never letting the LLM lift it', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    $profile = TrainingProfile::factory()->flaggedForSafetyReview('chest_pain')->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    sendTrainingMessage($tenant, '573001112233', 'ya estoy mejor, dame mi entrenamiento');

    expect($profile->fresh()->isFlaggedForSafetyReview())->toBeTrue();
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(0);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'profesional de la salud'));
});

it('accepts an audio message, transcribes it, and continues the training onboarding flow', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.test/audio.ogg'], 200),
        'cdn.example.test/*' => Http::response('fake-audio-bytes', 200),
        'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Quiero empezar a entrenar'], 200),
        'api.openai.com/v1/chat/completions' => Http::response(
            fakeOnboardingTurn(emptyExtraction(), 'ask_name', '¿Cómo te gustaría que te llame?'),
            200
        ),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', null, 'audio', 'media123');

    $contact = Contact::where('customer_phone', '573001112233')->first();
    expect($contact)->not->toBeNull();

    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->isOnboardingComplete($contact))->toBeFalse();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'audio/transcriptions'));
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '¿Cómo te gustaría que te llame?'));
});

// ── Bloque 4 (D047) ──────────────────────────────────────────────────────

it('TrainingHandler never sends the opportunistic invitation during the first two turns, but does from turn 3 onward', function () {
    // Política de turnos DEFINITIVA (corregida tras el bug real de umbral,
    // ver docs/DECISIONS.md D047): turno < 3 => nunca invitación oportunista,
    // sin importar el estado de blocking; turno >= 3 => sí, si queda alguna.
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Miguel']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(fakeOnboardingTurn(emptyExtraction(['goal' => 'lose_weight']), 'ask_experience_level', '¿Ya has entrenado antes?'))
            ->push(fakeOnboardingTurn(emptyExtraction(['experience_level' => 'intermediate']), 'ask_training_location', '¿Dónde vas a entrenar?'))
            ->push(fakeOnboardingTurn(emptyExtraction(['training_location' => 'gym']), 'ask_equipment', '¿Qué equipo tienes?'))
            ->push(fakeOnboardingTurn(emptyExtraction(['available_equipment' => [], 'health_condition_text' => '']), 'complete_onboarding', '¡Listo!')),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    // El primer turno necesita una palabra clave de entrenamiento — sin
    // TrainingProfile todavía, el Router no tiene otra señal para clasificar
    // el mensaje como Training (mismo mecanismo que el resto de tests de
    // este archivo, ver TrainingIntentClassifierTest).
    foreach (['Quiero perder peso', 'turno 2', 'turno 3', 'turno 4'] as $turn) {
        sendTrainingMessage($tenant, '573001112233', $turn);
    }

    $aiRequests = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.openai.com'))
        ->values();

    expect($aiRequests)->toHaveCount(4);

    $promptOf = fn (int $index) => data_get($aiRequests[$index][0]->data(), 'messages.0.content', '');

    expect($promptOf(0))->not->toContain('sin insistir'); // turno 1: sin invitación
    expect($promptOf(1))->not->toContain('sin insistir'); // turno 2: sin invitación (el bug corregido)
    expect($promptOf(2))->toContain('sin insistir'); // turno 3: SÍ invita
    expect($promptOf(2))->toContain('ajustar cuántos grupos musculares rotar por semana'); // sessions_per_week, primero en orden
});

it('Y: onboarding_turns increments exactly once per turn while incomplete, and never again after completion', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(fakeOnboardingTurn(emptyExtraction(['goal' => 'build_muscle']), 'ask_experience_level', 'Genial. ¿Ya has entrenado antes?'))
            ->push(fakeOnboardingTurn(
                emptyExtraction(['experience_level' => 'beginner', 'training_location' => 'home', 'available_equipment' => [], 'health_condition_text' => '']),
                'complete_onboarding',
                '¡Perfecto, ya tengo todo!'
            )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', 'Quiero ganar músculo');
    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile->fresh()->onboarding_turns)->toBe(1);

    sendTrainingMessage($tenant, '573001112233', 'Soy principiante, entreno en casa, sin equipo, sin lesiones');
    expect($profile->fresh()->onboarding_turns)->toBe(2);
    expect(app(\App\Training\Onboarding\OnboardingRequirementRegistry::class)->isOnboardingComplete($profile->fresh(), $contact->fresh()))->toBeTrue();

    // Onboarding ya completo — un mensaje adicional no debe incrementar el
    // contador ni volver a llamar a la IA (cae directo al chequeo de acceso).
    sendTrainingMessage($tenant, '573001112233', 'un mensaje cualquiera después de completar');
    expect($profile->fresh()->onboarding_turns)->toBe(2);

    $aiCalls = 0;
    foreach (Http::recorded() as [$request, $response]) {
        if (str_contains($request->url(), 'api.openai.com')) {
            $aiCalls++;
        }
    }
    expect($aiCalls)->toBe(2);
});

it('E2E: a single compound message providing everything blocking completes onboarding and generates the first session in one turn, with no extra questions', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => null]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    Exercise::factory()->create(['muscle_group' => 'chest', 'name' => 'Flexiones', 'video_url' => 'https://videos.example.test/pushup.mp4']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            fakeOnboardingTurn(
                emptyExtraction([
                    'name' => 'Carlos',
                    'goal' => 'build_muscle',
                    'experience_level' => 'beginner',
                    'training_location' => 'home',
                    'available_equipment' => [],
                    'health_condition_text' => '',
                ]),
                'complete_onboarding',
                '¡Perfecto Carlos! Ya tengo todo, aquí va tu entrenamiento.'
            ),
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage(
        $tenant,
        '573001112233',
        'Me llamo Carlos, quiero ganar músculo, soy principiante, entreno en casa, sin equipo y sin lesiones'
    );

    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect(app(\App\Training\Onboarding\OnboardingRequirementRegistry::class)->isOnboardingComplete($profile, $contact->fresh()))->toBeTrue();
    expect($contact->fresh()->customer_name)->toBe('Carlos');

    // Se entregó directamente la primera sesión — ninguna pregunta de
    // onboarding adicional en el medio.
    $session = WorkoutSession::where('contact_id', $contact->id)->first();
    expect($session)->not->toBeNull();
    expect($session->workoutExercises)->toHaveCount(1);

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'entrenamiento de hoy'));

    $aiCalls = 0;
    foreach (Http::recorded() as [$request, $response]) {
        if (str_contains($request->url(), 'api.openai.com')) {
            $aiCalls++;
        }
    }
    expect($aiCalls)->toBe(1); // exactamente 1 llamada de IA en total
});
