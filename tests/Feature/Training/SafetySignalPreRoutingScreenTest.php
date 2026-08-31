<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Training\Enums\SafetyStatus;
use App\Training\Support\SafetySignalDetector;
use Illuminate\Support\Facades\Http;

/**
 * Hito 7 (hallazgo de la prueba E2E real): una señal de seguridad real en
 * WhatsApp ("Me duele mucho el pecho, no puedo seguir") se enrutó a
 * fallback_chat porque el Contact ya tenía onboarding completo y ninguna
 * WorkoutSession pendiente — las dos únicas condiciones que
 * TrainingIntentClassifier usa para clasificar como `training`. La señal
 * nunca llegó a SafetySignalDetector; TrainingProfile.safety_status nunca
 * cambió; la respuesta fue una improvisación del LLM de fallback_chat, no un
 * escalamiento garantizado por código.
 *
 * Estos tests prueban el pipeline real completo (mismo patrón que
 * TrainingConversationFlowTest.php: vía el Job, no la clase en aislamiento)
 * para demostrar que PreRoutingScreener + SafetySignalPreRoutingScreen
 * cierran exactamente ese hallazgo, sin importar el estado de sesión.
 */

function sendSafetyTestMessage(Tenant $tenant, string $from, string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

it('flags a safety signal even with no active WorkoutSession and completed onboarding (the exact E2E failure)', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    // Ninguna WorkoutSession en absoluto — igual que después de completar el
    // reporte de la última sesión, exactamente el estado real que falló.

    // A propósito: solo se fakea el envío de WhatsApp, NO ningún endpoint de
    // chat-completions. Si el código cayera en fallback_chat (que sí llama a
    // IA para responder), Http lanzaría una excepción por una petición sin
    // fakear — esta es la prueba de que nunca llega ahí.
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendSafetyTestMessage($tenant, '573001112233', 'Me duele mucho el pecho, no puedo seguir');

    expect($profile->fresh()->safety_status)->toBe(SafetyStatus::FlaggedForReview);
    expect($profile->fresh()->safety_flag_reason)->toBe('chest_pain');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
        && data_get($request->data(), 'text.body') === SafetySignalDetector::ESCALATION_MESSAGE);
});

it('flags a safety signal for a brand-new contact with no profile at all yet', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendSafetyTestMessage($tenant, '573009998877', 'Siento presión en el pecho');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573009998877')->first();
    expect($contact)->not->toBeNull();

    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->safety_status)->toBe(SafetyStatus::FlaggedForReview);
});

it('still flags a safety signal when there IS an active pending WorkoutSession (no regression)', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->create(['contact_id' => $contact->id]); // Scheduled

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendSafetyTestMessage($tenant, '573001112233', 'me duele el pecho');

    expect($profile->fresh()->safety_status)->toBe(SafetyStatus::FlaggedForReview);
});

it('does not touch safety_status for an ordinary message with no safety signal', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            ['choices' => [['message' => ['content' => 'Claro, aquí tienes información sobre nuestros planes.']]]],
            200
        ),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendSafetyTestMessage($tenant, '573001112233', 'Hola, ¿cuánto cuesta el servicio?');

    expect($profile->fresh()->safety_status)->toBe(SafetyStatus::Normal);
});
