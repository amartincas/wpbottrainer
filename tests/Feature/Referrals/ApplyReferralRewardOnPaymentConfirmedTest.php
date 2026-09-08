<?php

use App\Models\AlertLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingAccessAudit;
use App\Models\WhatsAppMessage;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Events\PaymentConfirmed;
use App\Referrals\Enums\ReferralRewardApplicationStatus;
use App\Referrals\Listeners\ApplyReferralRewardOnPaymentConfirmed;
use App\Referrals\Models\Referral;
use App\Referrals\Models\ReferralReward;
use App\Training\Enums\TrainingAccessAuditAction;
use App\Training\Enums\TrainingAccessStatus;
use Illuminate\Support\Facades\Http;

/**
 * Hito 13 — el único consumidor real de `PaymentConfirmed`. Se prueba
 * invocando el listener DIRECTAMENTE (`->handle($event)`), NO disparando
 * el evento real vía `PaymentConfirmationService::confirm()` — bajo la
 * transacción envolvente de RefreshDatabase, `ShouldDispatchAfterCommit`
 * nunca ve un commit real y el listener registrado jamás se ejecutaría
 * (mismo hallazgo ya documentado en Hito 11). El cableado real
 * (`AppServiceProvider::boot()`) se verifica aparte, por inspección.
 */

function rewardListener(): ApplyReferralRewardOnPaymentConfirmed
{
    return app(ApplyReferralRewardOnPaymentConfirmed::class);
}

function openWaWindowForReferrer(Contact $contact): void
{
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now(),
    ]);
}

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
});

it('does nothing when the confirmed payment contact has no Referral at all', function () {
    $contact = Contact::factory()->create();
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);

    rewardListener()->handle(new PaymentConfirmed($payment));

    expect(ReferralReward::count())->toBe(0);
});

it('creates a reward on the first confirmed payment of a referred contact, with the tenant current reward_days as a snapshot', function () {
    $tenant = Tenant::factory()->create(['referral_reward_days' => 5]);
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $referred = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingAccess::factory()->for($referrer)->create(['status' => TrainingAccessStatus::Active, 'expires_at' => now()->addDays(10)]);
    $referral = Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referred->id]);
    $payment = Payment::factory()->create(['contact_id' => $referred->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);

    rewardListener()->handle(new PaymentConfirmed($payment));

    $reward = ReferralReward::sole();
    expect($reward->referral_id)->toBe($referral->id);
    expect($reward->payment_id)->toBe($payment->id);
    expect($reward->reward_days)->toBe(5);
    expect($reward->application_status)->toBe(ReferralRewardApplicationStatus::Applied);
});

it('does NOT reward a second/third confirmed payment of the same referred contact — first purchase only', function () {
    $referrer = Contact::factory()->create();
    $referred = Contact::factory()->create(['tenant_id' => $referrer->tenant_id]);
    TrainingAccess::factory()->for($referrer)->create(['status' => TrainingAccessStatus::Active, 'expires_at' => now()->addDays(10)]);
    Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referred->id]);

    $firstPayment = Payment::factory()->create(['contact_id' => $referred->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()->subDay()]);
    rewardListener()->handle(new PaymentConfirmed($firstPayment));

    $secondPayment = Payment::factory()->create(['contact_id' => $referred->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);
    rewardListener()->handle(new PaymentConfirmed($secondPayment));

    expect(ReferralReward::count())->toBe(1);
    expect(ReferralReward::sole()->payment_id)->toBe($firstPayment->id);
});

it('two different referred contacts each freeze their own snapshot of reward_days, even if the tenant changes it in between', function () {
    $tenant = Tenant::factory()->create(['referral_reward_days' => 3]);
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingAccess::factory()->for($referrer)->create(['status' => TrainingAccessStatus::Active, 'expires_at' => now()->addDays(30)]);

    $laura = Contact::factory()->create(['tenant_id' => $tenant->id]);
    Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $laura->id]);
    $lauraPayment = Payment::factory()->create(['contact_id' => $laura->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);
    rewardListener()->handle(new PaymentConfirmed($lauraPayment));

    $tenant->update(['referral_reward_days' => 5]);

    $carlos = Contact::factory()->create(['tenant_id' => $tenant->id]);
    Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $carlos->id]);
    $carlosPayment = Payment::factory()->create(['contact_id' => $carlos->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);
    rewardListener()->handle(new PaymentConfirmed($carlosPayment));

    expect(ReferralReward::where('payment_id', $lauraPayment->id)->sole()->reward_days)->toBe(3);
    expect(ReferralReward::where('payment_id', $carlosPayment->id)->sole()->reward_days)->toBe(5);
});

// ── TrainingAccess del referente por estado ─────────────────────────────

it('Active referrer (vigente): extends expires_at from its current value, records audit with performed_by null and referral_reward_id set, and notifies', function () {
    $referrer = Contact::factory()->create();
    openWaWindowForReferrer($referrer);
    $referred = Contact::factory()->create(['tenant_id' => $referrer->tenant_id]);
    $access = TrainingAccess::factory()->for($referrer)->create(['status' => TrainingAccessStatus::Active, 'expires_at' => now()->addDays(10)]);
    Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referred->id]);
    $payment = Payment::factory()->create(['contact_id' => $referred->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);

    rewardListener()->handle(new PaymentConfirmed($payment));

    $reward = ReferralReward::sole();
    expect($reward->application_status)->toBe(ReferralRewardApplicationStatus::Applied);
    expect($access->fresh()->expires_at->toDateString())->toBe(now()->addDays(13)->toDateString());

    $audit = TrainingAccessAudit::where('referral_reward_id', $reward->id)->sole();
    expect($audit->action)->toBe(TrainingAccessAuditAction::Extended);
    expect($audit->performed_by)->toBeNull();

    expect(WhatsAppMessage::where('idempotency_key', "referral_reward:{$reward->id}")->whereNotNull('dispatch_confirmed_at')->exists())->toBeTrue();
});

it('Trial referrer vencido (expired): extends from NOW, not from the past expiry', function () {
    $referrer = Contact::factory()->create();
    openWaWindowForReferrer($referrer);
    $referred = Contact::factory()->create(['tenant_id' => $referrer->tenant_id]);
    TrainingAccess::factory()->for($referrer)->create(['status' => TrainingAccessStatus::Trial, 'expires_at' => now()->subDays(2)]);
    Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referred->id]);
    $payment = Payment::factory()->create(['contact_id' => $referred->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);

    rewardListener()->handle(new PaymentConfirmed($payment));

    expect($referrer->trainingAccess->fresh()->expires_at->toDateString())->toBe(now()->addDays(3)->toDateString());
});

it('Free INDEFINIDO referrer: reward is generated (reward_days snapshot preserved), application_status is NotApplicable, expires_at stays null, no TrainingAccessAudit row, but still notifies', function () {
    $referrer = Contact::factory()->create();
    openWaWindowForReferrer($referrer);
    $referred = Contact::factory()->create(['tenant_id' => $referrer->tenant_id]);
    TrainingAccess::factory()->for($referrer)->create(['status' => TrainingAccessStatus::Free, 'expires_at' => null]);
    Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referred->id]);
    $payment = Payment::factory()->create(['contact_id' => $referred->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);

    rewardListener()->handle(new PaymentConfirmed($payment));

    $reward = ReferralReward::sole();
    expect($reward->application_status)->toBe(ReferralRewardApplicationStatus::NotApplicable);
    expect($reward->reward_days)->toBe(3);
    expect($referrer->trainingAccess->fresh()->expires_at)->toBeNull();
    expect(TrainingAccessAudit::where('referral_reward_id', $reward->id)->count())->toBe(0);
    expect(WhatsAppMessage::where('idempotency_key', "referral_reward:{$reward->id}")->whereNotNull('dispatch_confirmed_at')->exists())->toBeTrue();
});

it('Revoked referrer: reward is generated as Pending, TrainingAccess is NEVER touched, an Alert is emitted, and NO notification is sent', function () {
    $referrer = Contact::factory()->create();
    openWaWindowForReferrer($referrer);
    $referred = Contact::factory()->create(['tenant_id' => $referrer->tenant_id]);
    $access = TrainingAccess::factory()->for($referrer)->revoked()->create(['expires_at' => now()->addDays(5)]);
    $previousExpiresAt = $access->expires_at;
    Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referred->id]);
    $payment = Payment::factory()->create(['contact_id' => $referred->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);

    rewardListener()->handle(new PaymentConfirmed($payment));

    $reward = ReferralReward::sole();
    expect($reward->application_status)->toBe(ReferralRewardApplicationStatus::Pending);
    $fresh = $access->fresh();
    expect($fresh->status)->toBe(TrainingAccessStatus::Revoked); // nunca auto-reactivado
    expect($fresh->expires_at->toDateTimeString())->toBe($previousExpiresAt->toDateTimeString());
    expect(TrainingAccessAudit::where('referral_reward_id', $reward->id)->count())->toBe(0);
    expect(AlertLog::where('category', 'referrals')->exists())->toBeTrue();
    expect(WhatsAppMessage::where('idempotency_key', "referral_reward:{$reward->id}")->exists())->toBeFalse();
});

it('referrer without any TrainingAccess at all: reward is generated as Pending, same as Revoked', function () {
    $referrer = Contact::factory()->create();
    $referred = Contact::factory()->create(['tenant_id' => $referrer->tenant_id]);
    Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referred->id]);
    $payment = Payment::factory()->create(['contact_id' => $referred->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);

    rewardListener()->handle(new PaymentConfirmed($payment));

    expect(ReferralReward::sole()->application_status)->toBe(ReferralRewardApplicationStatus::Pending);
    expect(TrainingAccess::where('contact_id', $referrer->id)->exists())->toBeFalse(); // nunca se creó uno
});

// ── Idempotencia / concurrencia ──────────────────────────────────────────

it('the same PaymentConfirmed handled twice never produces two rewards', function () {
    $referrer = Contact::factory()->create();
    $referred = Contact::factory()->create(['tenant_id' => $referrer->tenant_id]);
    TrainingAccess::factory()->for($referrer)->create(['status' => TrainingAccessStatus::Active, 'expires_at' => now()->addDays(10)]);
    Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referred->id]);
    $payment = Payment::factory()->create(['contact_id' => $referred->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);

    $event = new PaymentConfirmed($payment->fresh());
    rewardListener()->handle($event);
    rewardListener()->handle($event); // retry / evento duplicado

    expect(ReferralReward::count())->toBe(1);
});

it('a second confirmed Payment of the referred contact processed BEFORE the true first one is correctly recognized as not-first, once the first is visible', function () {
    // Simula el caso de concurrencia: dos Payments del mismo referido ya
    // confirmados en BD (reviewed_at distinto), el listener del más
    // reciente corre PRIMERO — debe reconocer que NO es el primero.
    $referrer = Contact::factory()->create();
    $referred = Contact::factory()->create(['tenant_id' => $referrer->tenant_id]);
    TrainingAccess::factory()->for($referrer)->create(['status' => TrainingAccessStatus::Active, 'expires_at' => now()->addDays(10)]);
    Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referred->id]);

    $earlierPayment = Payment::factory()->create(['contact_id' => $referred->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()->subMinutes(5)]);
    $laterPayment = Payment::factory()->create(['contact_id' => $referred->id, 'status' => PaymentStatus::Confirmed, 'reviewed_at' => now()]);

    // El listener del Payment MÁS RECIENTE corre primero (orden de llegada
    // real del evento no garantiza orden cronológico de reviewed_at).
    rewardListener()->handle(new PaymentConfirmed($laterPayment));

    expect(ReferralReward::count())->toBe(0); // ya existe uno más antiguo visible — no es el primero

    rewardListener()->handle(new PaymentConfirmed($earlierPayment));

    $reward = ReferralReward::sole();
    expect($reward->payment_id)->toBe($earlierPayment->id); // la recompensa queda atada al REALMENTE primero
});

it('DetectsUniqueConstraintViolation only recognizes SQLSTATE 23000 — a different DB error is never treated as an idempotent no-op', function () {
    $trait = new class
    {
        use \App\Referrals\Support\DetectsUniqueConstraintViolation;

        public function check(\Illuminate\Database\QueryException $e): bool
        {
            return $this->isUniqueConstraintViolation($e);
        }
    };

    $uniqueViolation = new \Illuminate\Database\QueryException('mysql', 'insert into x', [], tap(new \PDOException('duplicate'), fn ($e) => $e->errorInfo = ['23000', 1062, 'Duplicate entry']));
    $otherError = new \Illuminate\Database\QueryException('mysql', 'insert into x', [], tap(new \PDOException('connection lost'), fn ($e) => $e->errorInfo = ['08S01', 2006, 'MySQL server has gone away']));

    expect($trait->check($uniqueViolation))->toBeTrue();
    expect($trait->check($otherError))->toBeFalse();
});

// ── Registro real del listener ───────────────────────────────────────────

it('ApplyReferralRewardOnPaymentConfirmed is really registered as a listener of PaymentConfirmed', function () {
    $rawListeners = app('events')->getRawListeners()[PaymentConfirmed::class] ?? [];

    expect(collect($rawListeners))->toContain(ApplyReferralRewardOnPaymentConfirmed::class);
});
