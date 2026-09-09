<?php

use App\Models\Contact;
use App\Models\Payment;
use App\Models\TrainingAccess;
use App\Models\TrainingAccessAudit;
use App\Payments\Enums\PaymentStatus;
use App\Training\Support\AutomaticTrialProvisioner;
use App\Training\Support\TrainingAccessAdministrationService;
use Illuminate\Support\Facades\DB;

/**
 * Hito 15 — `AutomaticTrialProvisioner`. Regla de elegibilidad ÚNICA:
 * nunca recibió Trial (trial_granted_at IS NULL) AND nunca tuvo un Payment
 * Confirmed. El disparo real (solo cuando el Gate deniega con 'no_access')
 * se prueba a nivel de TrainingHandler/E2E (tests/Feature/Commercial),
 * no aquí — este archivo cubre la lógica del servicio en sí.
 */
function provisioner(): AutomaticTrialProvisioner
{
    return app(AutomaticTrialProvisioner::class);
}

// ── Elegibilidad — la regla, no solo trial_granted_at ──────────────────────

it('grants an automatic Trial to a Contact who never had Trial and never had a confirmed Payment', function () {
    $tenant = \App\Models\Tenant::factory()->create(['trial_duration_days' => 5]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);

    $access = provisioner()->provisionIfEligible($contact);

    expect($access)->not->toBeNull();
    expect($access->status)->toBe(\App\Training\Enums\TrainingAccessStatus::Trial);
    expect($access->expires_at->diffInDays(now(), true))->toBeGreaterThan(4)->toBeLessThan(6);
});

it('uses each Tenant own trial_duration_days — never a hardcoded value', function () {
    $tenantA = \App\Models\Tenant::factory()->create(['trial_duration_days' => 5]);
    $tenantB = \App\Models\Tenant::factory()->create(['trial_duration_days' => 10]);
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    $accessA = provisioner()->provisionIfEligible($contactA);
    $accessB = provisioner()->provisionIfEligible($contactB);

    expect($accessA->expires_at->diffInDays(now(), true))->toBeGreaterThan(4)->toBeLessThan(6);
    expect($accessB->expires_at->diffInDays(now(), true))->toBeGreaterThan(9)->toBeLessThan(11);
});

it('does NOT grant a Trial to a Contact who already has trial_granted_at set', function () {
    $contact = Contact::factory()->create();
    app(TrainingAccessAdministrationService::class)->grantAutomaticTrial($contact, 5);

    $result = provisioner()->provisionIfEligible($contact->fresh());

    expect($result)->toBeNull();
    expect(TrainingAccess::where('contact_id', $contact->id)->count())->toBe(1); // nunca un segundo grant
});

it('does NOT grant a Trial to a Contact who has a confirmed Payment, EVEN with trial_granted_at still NULL (defensive — not relying solely on trial_granted_at)', function () {
    // Estado deliberadamente construido a mano (edge case defensivo): un
    // Contact con un Payment Confirmed histórico, pero SIN ninguna fila de
    // TrainingAccess (nunca pasó por PaymentConfirmationService::grantAccess()
    // en este escenario de prueba) — precisamente el caso que demuestra que
    // la regla NO depende únicamente de trial_granted_at.
    $contact = Contact::factory()->create();
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Confirmed]);

    $result = provisioner()->provisionIfEligible($contact->fresh());

    expect($result)->toBeNull();
    expect(TrainingAccess::where('contact_id', $contact->id)->exists())->toBeFalse();
});

it('a Payment that is NOT confirmed (pending/under_review/rejected/expired) never blocks eligibility', function (PaymentStatus $status) {
    $contact = Contact::factory()->create();
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => $status]);

    $result = provisioner()->provisionIfEligible($contact->fresh());

    expect($result)->not->toBeNull();
})->with([
    PaymentStatus::Pending, PaymentStatus::UnderReview, PaymentStatus::Rejected, PaymentStatus::Expired,
]);

it('records the auto-provisioned audit trail correctly on a real grant', function () {
    $contact = Contact::factory()->create();

    $access = provisioner()->provisionIfEligible($contact);

    $audit = TrainingAccessAudit::where('training_access_id', $access->id)->sole();
    expect($audit->auto_provisioned)->toBeTrue();
    expect($audit->performed_by)->toBeNull();
    expect($audit->referral_reward_id)->toBeNull();
});

// ── Concurrencia — mismo patrón que Payments/Referrals: instancias obsoletas ──

it('two concurrent requests for the same Contact grant the automatic Trial exactly once', function () {
    $contact = Contact::factory()->create();

    // Dos lecturas independientes, cada una "obsoleta" respecto a la otra —
    // simula dos procesos/requests concurrentes que leyeron el estado antes
    // de que cualquiera escribiera (mismo patrón ya establecido en
    // PaymentConfirmationServiceTest/ApplyReferralRewardOnPaymentConfirmedTest).
    $staleA = Contact::find($contact->id);
    $staleB = Contact::find($contact->id);

    $resultA = provisioner()->provisionIfEligible($staleA);
    $resultB = provisioner()->provisionIfEligible($staleB);

    // Exactamente una de las dos "ganó" — la segunda revalida DENTRO del
    // lock contra el estado ya committeado por la primera.
    expect(($resultA !== null) xor ($resultB !== null))->toBeTrue();
    expect(TrainingAccess::where('contact_id', $contact->id)->count())->toBe(1);
    expect(TrainingAccessAudit::where('auto_provisioned', true)
        ->whereHas('trainingAccess', fn ($q) => $q->where('contact_id', $contact->id))
        ->count())->toBe(1);
});

it('provisionIfEligible executes its lock/read inside a real DB transaction with a locking read (SELECT ... FOR UPDATE)', function () {
    $contact = Contact::factory()->create();

    DB::enableQueryLog();
    provisioner()->provisionIfEligible($contact);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $hasLockingSelect = collect($log)->contains(fn ($entry) => str_contains(mb_strtolower($entry['query']), 'for update'));

    expect($hasLockingSelect)->toBeTrue();
});
