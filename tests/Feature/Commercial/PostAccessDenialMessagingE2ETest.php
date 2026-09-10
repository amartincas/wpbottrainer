<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Training\Enums\TrainingAccessStatus;
use App\Training\Support\TrainingAccessAdministrationService;
use Illuminate\Support\Facades\Http;

/**
 * H16.1 (Cambio 4) — mensaje diferenciado al denegar acceso, según el
 * estado REAL leído de TrainingAccess/WorkoutSession — vía el Job real
 * (Router -> TrainingHandler -> TrainingAccessGate -> resolveAccessDeniedMessage()
 * -> TrialEndedMessageComposer). TrainingAccessGate sigue denegando
 * exactamente igual (reason='access_invalid' en los 3 casos) — solo cambia
 * el texto que el usuario recibe.
 */
function postDenialMessage(Tenant $tenant, string $from, ?string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

function completedProfileFor(Tenant $tenant, string $phone): Contact
{
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => $phone]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);

    return $contact;
}

it('Trial vencido CON sesiones completadas: usa TrialEndedMessageComposer con el conteo real, nunca el mensaje genérico', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = completedProfileFor($tenant, '573001180001');
    $access = app(TrainingAccessAdministrationService::class)->grantAutomaticTrial($contact, 5);
    $access->update(['expires_at' => now()->subDay()]); // vencido

    // 2 sesiones completadas reales — el hecho que el composer debe citar.
    WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);

    Http::fake([
        'api.openai.com/*' => Http::response('Server error', 500), // fuerza el fallback determinista
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    postDenialMessage($tenant, '573001180001', 'Dame mi entrenamiento de hoy');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'hiciste 2 sesiones conmigo'));
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'necesitas activar tu acceso'));
});

it('Trial vencido SIN ninguna sesión completada: usa el mismo mensaje genérico que "nunca tuvo acceso", nunca inventa una variante', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = completedProfileFor($tenant, '573001180002');
    $access = app(TrainingAccessAdministrationService::class)->grantAutomaticTrial($contact, 5);
    $access->update(['expires_at' => now()->subDay()]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    postDenialMessage($tenant, '573001180002', 'Dame mi entrenamiento de hoy');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'necesitas activar tu acceso'));
    // Nunca se llamó a la IA para este caso — no hay ningún hecho real que redactar.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
});

it('membresía Active vencida: usa TrialEndedMessageComposer con el hecho "paid_expired"', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = completedProfileFor($tenant, '573001180003');
    TrainingAccess::factory()->create([
        'contact_id' => $contact->id, 'status' => TrainingAccessStatus::Active, 'expires_at' => now()->subDay(),
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response('Server error', 500),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    postDenialMessage($tenant, '573001180003', 'Dame mi entrenamiento de hoy');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'nada de tu progreso se perdió'));
});

it('acceso Revoked: mensaje neutral orientado a revisión humana — nunca menciona Trial/membresía ni invita a pagar', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = completedProfileFor($tenant, '573001180004');
    TrainingAccess::factory()->create([
        'contact_id' => $contact->id, 'status' => TrainingAccessStatus::Revoked, 'expires_at' => now()->addDays(10),
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response('Server error', 500),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    postDenialMessage($tenant, '573001180004', 'Dame mi entrenamiento de hoy');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'pausado en este momento'));
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'quiero pagar'));
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Trial'));
});

it('nunca tuvo acceso: Contact inelegible para Trial (Payment confirmado previo) y sin ninguna fila TrainingAccess produce el mensaje genérico, sin ninguna llamada de IA', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = completedProfileFor($tenant, '573001180006');
    \App\Models\Payment::factory()->create(['contact_id' => $contact->id, 'status' => \App\Payments\Enums\PaymentStatus::Confirmed]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    postDenialMessage($tenant, '573001180006', 'Dame mi entrenamiento de hoy');

    expect(TrainingAccess::where('contact_id', $contact->id)->exists())->toBeFalse();
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'necesitas activar tu acceso'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
});
