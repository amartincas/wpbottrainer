<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WhatsAppMessage;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Hito 7: valida el camino REAL de entrada (HTTP POST al webhook, con el
 * payload exacto que Meta envía) hasta la base de datos — no solo el Job
 * construido directamente en PHP como hacían los tests de Hitos 4-6. Esto
 * es lo más cerca que se puede llegar de un E2E real con Meta sin tener
 * credenciales/teléfono reales (ver docs/DECISIONS.md, D021, y el runbook
 * en docs/E2E_META_RUNBOOK.md para la prueba en vivo pendiente).
 *
 * QUEUE_CONNECTION=sync en testing (ver phpunit.xml) — el Job se ejecuta de
 * forma síncrona dentro de la misma petición HTTP, así que se puede afirmar
 * sobre el estado final en base de datos justo después del POST.
 */

function realMetaTextPayload(string $wamid, string $from, string $phoneNumberId, string $body): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'waba-id-test',
            'changes' => [[
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [
                        'display_phone_number' => '15551234567',
                        'phone_number_id' => $phoneNumberId,
                    ],
                    'contacts' => [['profile' => ['name' => 'Usuario de prueba'], 'wa_id' => $from]],
                    'messages' => [[
                        'from' => $from,
                        'id' => $wamid,
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => $body],
                    ]],
                ],
                'field' => 'messages',
            ]],
        ]],
    ];
}

function realMetaAudioPayload(string $wamid, string $from, string $phoneNumberId, string $mediaId): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'waba-id-test',
            'changes' => [[
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '15551234567', 'phone_number_id' => $phoneNumberId],
                    'contacts' => [['profile' => ['name' => 'Usuario de prueba'], 'wa_id' => $from]],
                    'messages' => [[
                        'from' => $from,
                        'id' => $wamid,
                        'timestamp' => (string) time(),
                        'type' => 'audio',
                        'audio' => ['mime_type' => 'audio/ogg; codecs=opus', 'sha256' => 'fake-hash', 'id' => $mediaId],
                    ]],
                ],
                'field' => 'messages',
            ]],
        ]],
    ];
}

beforeEach(function () {
    Cache::flush();
});

it('processes a fully realistic Meta webhook payload end-to-end into Training onboarding', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'wa_phone_number_id' => '100000000000001']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode([
                'goal' => null, 'experience_level' => null, 'restrictions' => null,
                'available_equipment' => null, 'sessions_per_week' => null, 'safety_signal_text' => null,
            ])]]]])
            ->push(['choices' => [['message' => ['content' => '¿Cuál es tu objetivo principal?']]]]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    $payload = realMetaTextPayload('wamid.REAL1', '573001112233', '100000000000001', 'Quiero empezar a entrenar');

    $response = $this->postJson('/api/whatsapp/webhook/anything', $payload);

    $response->assertOk();
    $response->assertSee('EVENT_RECEIVED');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001112233')->first();
    expect($contact)->not->toBeNull();
    expect(TrainingProfile::where('contact_id', $contact->id)->exists())->toBeTrue();
    expect(WhatsAppMessage::where('tenant_id', $tenant->id)->where('role', 'user')->where('content', 'Quiero empezar a entrenar')->exists())->toBeTrue();
});

it('processes a fully realistic Meta audio webhook payload end-to-end, including transcription', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'wa_phone_number_id' => '100000000000002']);

    Http::fake([
        'graph.facebook.com/*/media456' => Http::response(['url' => 'https://cdn.example.test/audio.ogg'], 200),
        'cdn.example.test/*' => Http::response('fake-audio-bytes', 200),
        'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Quiero empezar a entrenar'], 200),
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode([
                'goal' => null, 'experience_level' => null, 'restrictions' => null,
                'available_equipment' => null, 'sessions_per_week' => null, 'safety_signal_text' => null,
            ])]]]])
            ->push(['choices' => [['message' => ['content' => '¿Cuál es tu objetivo?']]]]),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    $payload = realMetaAudioPayload('wamid.REALAUDIO1', '573001112233', '100000000000002', 'media456');

    $this->postJson('/api/whatsapp/webhook/anything', $payload)->assertOk();

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001112233')->first();
    expect($contact)->not->toBeNull();
    expect(TrainingProfile::where('contact_id', $contact->id)->exists())->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'audio/transcriptions'));
});

it('does not process a retried Meta webhook delivery twice (idempotency at the Training/report level)', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'wa_phone_number_id' => '100000000000003']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $exercise = Exercise::factory()->create(['name' => 'Sentadilla']);
    $workoutExercise = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'reports' => [[
                'exercise_name' => 'Sentadilla', 'not_performed' => false,
                'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
                'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
            ]],
            'session_finished' => false,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    $payload = realMetaTextPayload('wamid.RETRY1', '573001112233', '100000000000003', 'Sentadilla 10x40');

    // Meta reintenta la misma entrega (mismo WAMID) — comportamiento real
    // documentado de Meta cuando no recibe un 200 a tiempo, o simplemente
    // por su propia política de reintentos.
    $this->postJson('/api/whatsapp/webhook/anything', $payload)->assertOk();
    $this->postJson('/api/whatsapp/webhook/anything', $payload)->assertOk();

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercise->id)->count())->toBe(1);
    expect(WhatsAppMessage::where('tenant_id', $tenant->id)->where('role', 'user')->count())->toBe(1);
});

it('persists the outbound message and logs the failure when Meta rejects the send, without breaking the webhook response', function () {
    $tenant = Tenant::factory()->create(['wa_phone_number_id' => '100000000000004']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    // Hito 15 — sin TrainingAccess, pero YA tuvo un Trial antes (ahora
    // revocado) -> NO es elegible para Trial automático -> respuesta
    // determinista de "activa el servicio", sin necesitar IA, para aislar
    // el caso de "Meta responde error" (un Contact recién llegado, en
    // cambio, recibiría un Trial automático y SÍ requeriría una llamada de
    // IA para decidir qué responder — fuera del propósito de este test).
    app(\App\Training\Support\TrainingAccessAdministrationService::class)->grantAutomaticTrial($contact, 5);
    app(\App\Training\Support\TrainingAccessAdministrationService::class)->revoke($contact->fresh(), \App\Models\User::factory()->create(['is_super_admin' => true]), 'motivo de prueba');

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

    $payload = realMetaTextPayload('wamid.METAFAIL1', '573001112233', '100000000000004', 'Dame mi entrenamiento de hoy');

    $response = $this->postJson('/api/whatsapp/webhook/anything', $payload);

    // El webhook debe seguir respondiendo 200 a Meta aunque el envío de la
    // respuesta al usuario haya fallado — de lo contrario Meta reintentaría
    // la entrega indefinidamente.
    $response->assertOk();

    $botMessage = WhatsAppMessage::where('tenant_id', $tenant->id)->where('role', 'assistant')->first();
    expect($botMessage)->not->toBeNull();
    // H16.1 (Cambio 4) — este Contact tiene acceso Revoked (no simplemente
    // "nunca tuvo acceso"), así que resolveAccessDeniedMessage() ya no usa
    // el mensaje genérico de "activar tu acceso"/"quiero pagar" — produce el
    // mensaje neutral orientado a revisión humana (determinista, sin IA, ver
    // TrialEndedMessageComposer::fallbackFor()). Lo relevante para este test
    // sigue intacto: el mensaje se persiste igual aunque Meta rechace el envío.
    expect($botMessage->content)->toContain('pausado en este momento');
    expect($botMessage->content)->not->toContain('quiero pagar');
});

it('degrades gracefully through the real webhook route when the AI provider fails during onboarding', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'wa_phone_number_id' => '100000000000005']);

    Http::fake([
        'api.openai.com/*' => Http::response('Server error', 500),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    $payload = realMetaTextPayload('wamid.AIFAIL1', '573001112233', '100000000000005', 'Quiero entrenar');

    $response = $this->postJson('/api/whatsapp/webhook/anything', $payload);

    $response->assertOk();

    // Aun sin IA disponible, el onboarding no se rompe: se envía la
    // pregunta canónica de respaldo (App\Training\Support\OnboardingConversationService).
    $botMessage = WhatsAppMessage::where('tenant_id', $tenant->id)->where('role', 'assistant')->first();
    expect($botMessage)->not->toBeNull();
    expect($botMessage->content)->toBeString()->not->toBeEmpty();
});

it('keeps two tenants fully isolated when both receive real webhook deliveries', function () {
    $tenantA = Tenant::factory()->create(['wa_phone_number_id' => '200000000000001']);
    $tenantB = Tenant::factory()->create(['wa_phone_number_id' => '200000000000002']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    $this->postJson('/api/whatsapp/webhook/anything', realMetaTextPayload('wamid.TENANTA', '573001110001', '200000000000001', 'Quiero entrenar'))->assertOk();
    $this->postJson('/api/whatsapp/webhook/anything', realMetaTextPayload('wamid.TENANTB', '573001110002', '200000000000002', 'Quiero entrenar'))->assertOk();

    $contactA = Contact::where('tenant_id', $tenantA->id)->first();
    $contactB = Contact::where('tenant_id', $tenantB->id)->first();

    expect($contactA)->not->toBeNull();
    expect($contactB)->not->toBeNull();
    expect($contactA->customer_phone)->toBe('573001110001');
    expect($contactB->customer_phone)->toBe('573001110002');
    expect(TrainingProfile::whereHas('contact', fn ($q) => $q->where('tenant_id', $tenantA->id))->count())->toBe(1);
    expect(TrainingProfile::whereHas('contact', fn ($q) => $q->where('tenant_id', $tenantB->id))->count())->toBe(1);
});
