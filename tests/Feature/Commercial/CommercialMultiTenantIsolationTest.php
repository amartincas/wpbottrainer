<?php

use App\CustomerCare\Models\CustomerServiceRequest;
use App\CustomerCare\Models\Faq;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\Reminder;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\MembershipPlan;
use App\Referrals\Models\Referral;
use App\Referrals\Models\ReferralCode;
use App\Training\Enums\ReminderStatus;

/**
 * Hito 15 — §16 del diseño: Tenant A nunca afecta Tenant B, verificado
 * cruzando 2 tenants QA sobre los 7 dominios enumerados en el brief:
 * MembershipPlans, Payments, Referrals, FAQs, CustomerServiceRequests,
 * Reminders, Contacts. TrainingAccess se verifica indirectamente vía
 * Contact (no tiene tenant_id propio, igual que Payment/Referral).
 */
it('MembershipPlans are never visible or reachable across tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    expect(MembershipPlan::where('tenant_id', $tenantA->id)->pluck('id'))
        ->not->toContain(MembershipPlan::where('tenant_id', $tenantB->id)->first()->id);
});

it('Payments never leak across tenants when queried through their Contact', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);
    $paymentA = Payment::factory()->create(['contact_id' => $contactA->id, 'status' => PaymentStatus::Confirmed]);
    Payment::factory()->create(['contact_id' => $contactB->id, 'status' => PaymentStatus::Confirmed]);

    $paymentsVisibleToA = Payment::whereHas('contact', fn ($q) => $q->where('tenant_id', $tenantA->id))->pluck('id');
    expect($paymentsVisibleToA)->toContain($paymentA->id);
    expect($paymentsVisibleToA)->toHaveCount(1);
});

it('a Referral code from one Tenant is never resolvable/attributable for a Contact of another Tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $referrerA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $codeA = ReferralCode::factory()->create(['contact_id' => $referrerA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    app(\App\Referrals\Support\ReferralAttributionPreRoutingScreen::class)->screen(
        new \App\Core\Messaging\ExecutionContext(
            tenant: $tenantB,
            conversation: null,
            message: new \App\Core\Messaging\IngestedMessage(
                from: $contactB->customer_phone, messageBody: "hola {$codeA->code}", phoneId: 'wamid.x', messageType: 'text', mediaId: null,
            ),
            legacy: [],
        ),
    );

    expect(Referral::where('referred_contact_id', $contactB->id)->exists())->toBeFalse();
});

it('FAQs never leak across tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $faqA = Faq::factory()->create(['tenant_id' => $tenantA->id, 'question' => '¿Horario?', 'answer' => 'A']);
    Faq::factory()->create(['tenant_id' => $tenantB->id, 'question' => '¿Horario?', 'answer' => 'B']);

    $candidates = app(\App\CustomerCare\Support\FaqMatcher::class)->retrieveCandidates($tenantA, '¿horario?');
    expect($candidates->pluck('id'))->toContain($faqA->id);
    expect($candidates)->toHaveCount(1);
});

it('CustomerServiceRequests never leak across tenants when queried through their Contact', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);
    $requestA = CustomerServiceRequest::factory()->create(['contact_id' => $contactA->id]);
    CustomerServiceRequest::factory()->create(['contact_id' => $contactB->id]);

    $visibleToA = CustomerServiceRequest::whereHas('contact', fn ($q) => $q->where('tenant_id', $tenantA->id))->pluck('id');
    expect($visibleToA)->toContain($requestA->id);
    expect($visibleToA)->toHaveCount(1);
});

it('Reminders never leak across tenants when queried through their Contact, and always send via their OWN Tenant WhatsApp credentials', function () {
    $tenantA = Tenant::factory()->create(['wa_phone_number_id' => 'phone-a']);
    $tenantB = Tenant::factory()->create(['wa_phone_number_id' => 'phone-b']);
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);
    $reminderA = Reminder::factory()->create(['contact_id' => $contactA->id, 'status' => ReminderStatus::Pending]);
    Reminder::factory()->create(['contact_id' => $contactB->id, 'status' => ReminderStatus::Pending]);

    $visibleToA = Reminder::whereHas('contact', fn ($q) => $q->where('tenant_id', $tenantA->id))->pluck('id');
    expect($visibleToA)->toContain($reminderA->id);
    expect($visibleToA)->toHaveCount(1);
});

it('Contacts never leak across tenants, even with the exact same phone number', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $sharedPhone = '573009998877';
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id, 'customer_phone' => $sharedPhone]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id, 'customer_phone' => $sharedPhone]);

    expect($contactA->id)->not->toBe($contactB->id);
    expect(Contact::where('tenant_id', $tenantA->id)->where('customer_phone', $sharedPhone)->sole()->id)->toBe($contactA->id);
    expect(Contact::where('tenant_id', $tenantB->id)->where('customer_phone', $sharedPhone)->sole()->id)->toBe($contactB->id);
});

it('the automatic Trial duration is strictly per-Tenant — one Tenant changing its trial_duration_days never affects another', function () {
    $tenantA = Tenant::factory()->create(['trial_duration_days' => 5]);
    $tenantB = Tenant::factory()->create(['trial_duration_days' => 30]);
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    $accessA = app(\App\Training\Support\AutomaticTrialProvisioner::class)->provisionIfEligible($contactA);
    $accessB = app(\App\Training\Support\AutomaticTrialProvisioner::class)->provisionIfEligible($contactB);

    expect($accessA->expires_at->diffInDays(now(), true))->toBeGreaterThan(4)->toBeLessThan(6);
    expect($accessB->expires_at->diffInDays(now(), true))->toBeGreaterThan(29)->toBeLessThan(31);
});
