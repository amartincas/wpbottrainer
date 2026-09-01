<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
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
        'goal' => null, 'experience_level' => null, 'restrictions' => null,
        'available_equipment' => null, 'sessions_per_week' => null, 'safety_signal_text' => null,
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
        'api.openai.com/v1/chat/completions' => Http::response(
            fakeOnboardingTurn(emptyExtraction(), 'ask_goal', '¿Cuál es tu objetivo principal?'),
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', 'Quiero empezar a entrenar');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001112233')->first();
    expect($contact)->not->toBeNull();

    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->isOnboardingComplete())->toBeFalse();
    expect($profile->goal)->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v20.0/'.$tenant->wa_phone_number_id.'/messages'
        && str_contains(data_get($request->data(), 'text.body', ''), '¿Cuál es tu objetivo principal?'));

    // Hito 5.1: UNA sola llamada a la IA por turno (antes eran 2).
    Http::assertSentCount(2); // 1 a la IA + 1 a WhatsApp
});

it('persists progressively answered onboarding fields turn by turn, without re-asking what is already known', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(fakeOnboardingTurn(
                emptyExtraction(['goal' => 'build_muscle']),
                'ask_experience_level',
                '¿Cuál es tu nivel de experiencia?'
            ))
            ->push(fakeOnboardingTurn(
                emptyExtraction(['experience_level' => 'beginner', 'restrictions' => [], 'available_equipment' => [], 'sessions_per_week' => 4]),
                'complete_onboarding',
                '¡Perfecto, ya tengo todo!'
            )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', 'Quiero ganar músculo');

    $contact = Contact::where('customer_phone', '573001112233')->first();
    $profile = TrainingProfile::where('contact_id', $contact->id)->first();

    expect($profile->goal->value)->toBe('build_muscle');
    expect($profile->experience_level)->toBeNull();

    // Segundo turno: responde el resto de golpe — onboarding se completa en
    // este turno, cae directo al chequeo de acceso (sin narración extra).
    sendTrainingMessage($tenant, '573001112233', 'Soy principiante, sin lesiones, sin equipo, 4 veces por semana');

    $profile->refresh();
    expect($profile->isOnboardingComplete())->toBeTrue();
    expect($profile->experience_level->value)->toBe('beginner');
    expect($profile->goal->value)->toBe('build_muscle'); // conservado del primer turno
    expect($profile->sessions_per_week)->toBe(4);

    // Sin acceso todavía -> se le informa que debe activar el servicio, con
    // instrucción explícita de cómo hacerlo (Hito 8.1 — el mensaje anterior
    // ("Contáctanos") no invitaba a decir "quiero pagar").
    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v20.0/'.$tenant->wa_phone_number_id.'/messages'
        && str_contains(data_get($request->data(), 'text.body', ''), 'activar tu acceso')
        && str_contains(data_get($request->data(), 'text.body', ''), 'quiero pagar'));
});

// 11 y 12 (Hito 5.1): flujo completo de onboarding turno a turno + exactamente
// UNA llamada HTTP a IA por cada turno incompleto (no 2).
it('completes the full onboarding conversation turn by turn with exactly ONE AI call per incomplete turn', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(fakeOnboardingTurn(emptyExtraction(['goal' => 'lose_weight']), 'ask_experience_level', 'Genial, vamos a perder peso. ¿Ya has entrenado antes?'))
            ->push(fakeOnboardingTurn(emptyExtraction(['experience_level' => 'intermediate']), 'ask_restrictions', '¿Alguna lesión o dolor que deba saber?'))
            ->push(fakeOnboardingTurn(emptyExtraction(['restrictions' => ['dolor en las rodillas']]), 'ask_equipment', 'Anotado. ¿Qué equipo tienes?'))
            ->push(fakeOnboardingTurn(emptyExtraction(['available_equipment' => []]), 'ask_sessions_per_week', '¿Cuántos días puedes entrenar?'))
            ->push(fakeOnboardingTurn(emptyExtraction(['sessions_per_week' => 3]), 'complete_onboarding', '¡Listo, ya tengo tu perfil completo!')),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    $turns = ['Quiero perder peso', 'Intermedio', 'Dolor en las rodillas', 'Ninguno', '3 veces'];

    foreach ($turns as $turn) {
        sendTrainingMessage($tenant, '573001112233', $turn);
    }

    $contact = Contact::where('customer_phone', '573001112233')->first();
    $profile = TrainingProfile::where('contact_id', $contact->id)->first();

    expect($profile->isOnboardingComplete())->toBeTrue();
    expect($profile->goal->value)->toBe('lose_weight');
    expect($profile->experience_level->value)->toBe('intermediate');
    expect($profile->restrictions)->toBe(['dolor en las rodillas']);
    expect($profile->sessions_per_week)->toBe(3);

    // Exactamente 5 llamadas a la IA — una por cada uno de los 5 turnos,
    // nunca 2 por turno (línea base pre-Hito 5.1 habría sido hasta 9: 5
    // extracciones + 4 narraciones, ya que el último turno completa el
    // perfil y no necesita narrar).
    Http::assertSentCount(5 + count($turns)); // 5 a la IA + 5 a WhatsApp (una respuesta por turno)

    $aiCalls = 0;
    foreach (Http::recorded() as [$request, $response]) {
        if (str_contains($request->url(), 'api.openai.com')) {
            $aiCalls++;
        }
    }
    expect($aiCalls)->toBe(5);
});

it('informs the user they need to activate the service when access is denied, with an explicit instruction (Hito 8.1)', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]); // complete, no TrainingAccess row

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
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'restrictions' => [], 'available_equipment' => []]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $chest = Exercise::factory()->create(['muscle_group' => 'chest', 'name' => 'Flexiones', 'video_url' => 'https://videos.example.test/pushup.mp4']);
    $legs = Exercise::factory()->create(['muscle_group' => 'legs', 'name' => 'Sentadilla', 'video_url' => 'https://videos.example.test/squat.mp4']);
    $back = Exercise::factory()->create(['muscle_group' => 'back', 'name' => 'Remo', 'video_url' => 'https://videos.example.test/row.mp4']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    sendTrainingMessage($tenant, '573001112233', 'Quiero mi entrenamiento de hoy');

    $session = WorkoutSession::where('contact_id', $contact->id)->first();
    expect($session)->not->toBeNull();
    expect($session->workoutExercises)->toHaveCount(3);

    // El texto del entrenamiento se envió.
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'entrenamiento de hoy'));

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
            fakeOnboardingTurn(emptyExtraction(), 'ask_goal', '¿Cuál es tu objetivo?'),
            200
        ),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', null, 'audio', 'media123');

    $contact = Contact::where('customer_phone', '573001112233')->first();
    expect($contact)->not->toBeNull();

    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->isOnboardingComplete())->toBeFalse();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'audio/transcriptions'));
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '¿Cuál es tu objetivo?'));
});
