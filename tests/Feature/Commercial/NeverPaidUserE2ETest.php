<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Training\Enums\TrainingAccessStatus;
use App\Training\Support\TrainingAccessAdministrationService;
use Illuminate\Support\Facades\Http;

/**
 * Hito 15 — Escenario F: Trial (automático) -> expira -> nunca paga. Nunca
 * genera Active ni otro Trial por accidente; el Contact conserva acceso
 * normal a Customer Service/Payment/Referral después de que su Trial
 * expiró.
 */
function commercialNeverPaidMessage(Tenant $tenant, string $from, ?string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

it('a Trial that expires without ever paying never becomes Active, and never grants a second Trial on the next attempt to train', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001140001']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    $access = app(TrainingAccessAdministrationService::class)->grantAutomaticTrial($contact, 5);
    // Simula el paso del tiempo — el Trial ya venció, nunca hubo Payment.
    $access->update(['expires_at' => now()->subDay()]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    commercialNeverPaidMessage($tenant, '573001140001', 'Dame mi entrenamiento de hoy');

    expect(TrainingAccess::where('contact_id', $contact->id)->count())->toBe(1); // nunca una segunda fila
    $fresh = TrainingAccess::where('contact_id', $contact->id)->sole();
    expect($fresh->status)->toBe(TrainingAccessStatus::Trial); // la columna cruda nunca se auto-muta
    expect($fresh->isCurrentlyValid())->toBeFalse();
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(0);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'activar tu acceso')
        && str_contains(data_get($request->data(), 'text.body', ''), 'quiero pagar'));
});

it('a Contact whose Trial expired can still start Payment, use Customer Service, and generate a Referral — nothing is locked out', function () {
    $tenant = Tenant::factory()->create(['monthly_price' => 50000, 'nequi_number' => '300-111-2222']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001140002']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    $access = app(TrainingAccessAdministrationService::class)->grantAutomaticTrial($contact, 5);
    $access->update(['expires_at' => now()->subDay()]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    // Payment: "quiero pagar" funciona con normalidad.
    commercialNeverPaidMessage($tenant, '573001140002', 'Quiero pagar con Nequi');
    expect(\App\Models\Payment::where('contact_id', $contact->id)->exists())->toBeTrue();

    // Customer Service: petición explícita de ayuda humana funciona con
    // normalidad (camino independiente, cero AI) para un Contact NUEVO
    // (distinto número, mismo Tenant) sin acceso.
    $contact2 = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001140003']);
    commercialNeverPaidMessage($tenant, '573001140003', 'Necesito hablar con alguien');
    expect(\App\CustomerCare\Models\CustomerServiceRequest::where('contact_id', $contact2->id)->exists())->toBeTrue();

    // Referral: un Contact activo (aunque sea otro, el generador de código
    // no exige TrainingAccess vigente) puede pedir su código de invitación.
    commercialNeverPaidMessage($tenant, '573001140004', 'Dame mi código de referido');
    $referrerContact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001140004')->sole();
    expect(\App\Referrals\Models\ReferralCode::where('contact_id', $referrerContact->id)->exists())->toBeTrue();
});

it('an admin-granted Trial (manual, via Filament) follows the exact same "never a second Trial after expiry" rule as an automatic one', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001140005']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    $admin = User::factory()->create(['is_super_admin' => true]);
    $access = app(TrainingAccessAdministrationService::class)->grantTrial($contact, $admin, 5, 'cortesía');
    $access->update(['expires_at' => now()->subDay()]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    commercialNeverPaidMessage($tenant, '573001140005', 'Dame mi entrenamiento de hoy');

    expect(TrainingAccess::where('contact_id', $contact->id)->count())->toBe(1);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'activar tu acceso'));
});
