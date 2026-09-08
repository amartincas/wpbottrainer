<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Models\Contact;
use App\Models\Tenant;
use App\Payments\Enums\PaymentMethodType;
use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use App\Referrals\Models\Referral;
use App\Referrals\Models\ReferralCode;
use App\Referrals\Support\ReferralAttributionPreRoutingScreen;
use App\Training\Enums\TrainingAccessStatus;
use App\Models\TrainingAccess;

/**
 * Hito 13 — atribución. `screen()` SIEMPRE debe retornar `false` (nunca
 * reclama el pipeline) — se verifica explícitamente en cada caso, no solo
 * el efecto sobre `Referral`.
 */

function referralContext(Tenant $tenant, string $from, string $body): ExecutionContext
{
    return new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage($from, $body, 'wamid-test', 'text', null),
    );
}

function screen(): ReferralAttributionPreRoutingScreen
{
    return app(ReferralAttributionPreRoutingScreen::class);
}

it('creates a Referral when a valid code from the same tenant is present', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id, 'code' => 'REF-AB23CD']);

    $result = screen()->screen(referralContext($tenant, '573001112222', "Hola quiero unirme {$code->code}"));

    expect($result)->toBeFalse();
    $referral = Referral::sole();
    expect($referral->referrer_contact_id)->toBe($referrer->id);
    expect($referral->code)->toBe('REF-AB23CD');
    $invited = Contact::where('customer_phone', '573001112222')->where('tenant_id', $tenant->id)->sole();
    expect($referral->referred_contact_id)->toBe($invited->id);
});

it('never claims the pipeline, even on a valid attribution', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);

    $result = screen()->screen(referralContext($tenant, '573000000001', $code->code));

    expect($result)->toBeFalse();
});

it('is a no-op when the message has no recognizable code', function () {
    $tenant = Tenant::factory()->create();

    $result = screen()->screen(referralContext($tenant, '573000000002', 'Hola, quiero entrenar'));

    expect($result)->toBeFalse();
    expect(Referral::count())->toBe(0);
});

it('is a no-op when the code does not exist', function () {
    $tenant = Tenant::factory()->create();

    screen()->screen(referralContext($tenant, '573000000003', 'REF-ZZ99ZZ'));

    expect(Referral::count())->toBe(0);
});

it('blocks cross-tenant attribution — a code from another tenant never attributes', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $referrerInA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrerInA->id]);

    // El invitado escribe al WhatsApp del Tenant B, con el código del Tenant A.
    screen()->screen(referralContext($tenantB, '573000000004', $code->code));

    expect(Referral::count())->toBe(0);
});

it('blocks self-referral deterministically', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573000000005']);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);

    // El mismo número que generó el código lo usa "sobre sí mismo".
    screen()->screen(referralContext($tenant, '573000000005', $code->code));

    expect(Referral::count())->toBe(0);
});

it('the first attribution wins — a second, different code never replaces it (Juan/Pedro/Laura)', function () {
    $tenant = Tenant::factory()->create();
    $juan = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $pedro = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $juanCode = ReferralCode::factory()->create(['contact_id' => $juan->id]);
    $pedroCode = ReferralCode::factory()->create(['contact_id' => $pedro->id]);

    screen()->screen(referralContext($tenant, '573000000006', $juanCode->code));
    screen()->screen(referralContext($tenant, '573000000006', $pedroCode->code));

    $referral = Referral::sole();
    expect($referral->referrer_contact_id)->toBe($juan->id); // Juan gana, Pedro no obtiene nada
});

it('a contact with a prior Trial (never paid) is still eligible', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);
    $invited = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573000000007']);
    TrainingAccess::factory()->for($invited)->create(['status' => TrainingAccessStatus::Trial]);

    screen()->screen(referralContext($tenant, '573000000007', $code->code));

    expect(Referral::where('referred_contact_id', $invited->id)->exists())->toBeTrue();
});

it('a contact with a prior Free grant (never paid) is still eligible', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);
    $invited = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573000000008']);
    TrainingAccess::factory()->for($invited)->create(['status' => TrainingAccessStatus::Free, 'expires_at' => null]);

    screen()->screen(referralContext($tenant, '573000000008', $code->code));

    expect(Referral::where('referred_contact_id', $invited->id)->exists())->toBeTrue();
});

it('a contact whose only Payments are rejected/expired/pending/under_review is still eligible', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);
    $invited = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573000000009']);

    foreach ([PaymentStatus::Rejected, PaymentStatus::Expired, PaymentStatus::Pending, PaymentStatus::UnderReview] as $status) {
        Payment::factory()->create(['contact_id' => $invited->id, 'status' => $status, 'method' => PaymentMethodType::ManualTransfer]);
    }

    screen()->screen(referralContext($tenant, '573000000009', $code->code));

    expect(Referral::where('referred_contact_id', $invited->id)->exists())->toBeTrue();
});

it('a contact who already had a CONFIRMED Payment before is NOT eligible — never "Contact does not exist" as the criterion', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);
    $invited = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573000000010']);
    Payment::factory()->create(['contact_id' => $invited->id, 'status' => PaymentStatus::Confirmed]);

    screen()->screen(referralContext($tenant, '573000000010', $code->code));

    expect(Referral::where('referred_contact_id', $invited->id)->exists())->toBeFalse();
});

it('does nothing when the referral program is disabled for the tenant, even with a valid code', function () {
    $tenant = Tenant::factory()->create(['referral_program_enabled' => false]);
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);

    screen()->screen(referralContext($tenant, '573000000011', $code->code));

    expect(Referral::count())->toBe(0);
});

it('a second message with the same already-used code for a different sender never creates a second attribution for the same referred contact', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);

    screen()->screen(referralContext($tenant, '573000000012', $code->code));
    screen()->screen(referralContext($tenant, '573000000012', $code->code)); // reintento del mismo remitente

    expect(Referral::count())->toBe(1);
});
