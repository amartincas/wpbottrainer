<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AlertLog;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrainingProfile;
use App\Models\User;
use App\Training\Enums\SafetyStatus;
use App\Training\Support\SafetySignalDetector;
use Illuminate\Support\Facades\Http;

/**
 * Hito 7.1: conecta Safety con AlertService (señal → flagForSafetyReview()
 * → AlertService → WhatsApp superadmin), con la garantía explícita de que
 * el bloqueo de seguridad sigue funcionando aunque falle el envío de la
 * alerta — mismo patrón de pipeline real que
 * SafetySignalPreRoutingScreenTest.php.
 */

function sendSafetyAlertTestMessage(Tenant $tenant, string $from, string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

it('emits an AlertLog and a WhatsApp alert to the super-admin when a safety signal is flagged', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    User::factory()->create(['is_super_admin' => true, 'phone' => '573009990000']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendSafetyAlertTestMessage($tenant, '573001112233', 'Me duele mucho el pecho, no puedo seguir');

    expect($profile->fresh()->safety_status)->toBe(SafetyStatus::FlaggedForReview);

    $alertLog = AlertLog::where('category', 'safety')->first();
    expect($alertLog)->not->toBeNull();
    expect($alertLog->severity)->toBe('critical');
    expect($alertLog->context['contact_id'])->toBe($contact->id);

    Http::assertSent(fn ($request) => ($request['to'] ?? null) === '573009990000'
        && str_contains($request['text']['body'] ?? '', 'Señal de seguridad'));
});

it('still blocks and escalates to the user even when the WhatsApp alert delivery fails', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    User::factory()->create(['is_super_admin' => true, 'phone' => '573009990000']);

    // El envío de la alerta al admin falla (500) — el mensaje AL USUARIO
    // (customer_phone distinto) debe seguir enviándose sin problema.
    Http::fake([
        'graph.facebook.com/*/messages' => Http::sequence()
            ->push(['error' => 'simulated failure'], 500) // intento de WhatsAppAdminAlertChannel
            ->push(['messages' => [['id' => 'wamid.OUT']]], 200), // reply() al usuario real
    ]);

    sendSafetyAlertTestMessage($tenant, '573001112233', 'Me duele mucho el pecho, no puedo seguir');

    expect($profile->fresh()->safety_status)->toBe(SafetyStatus::FlaggedForReview);

    Http::assertSent(fn ($request) => ($request['to'] ?? null) === '573001112233'
        && ($request['text']['body'] ?? null) === SafetySignalDetector::ESCALATION_MESSAGE);
});
