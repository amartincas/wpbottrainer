<?php

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\User;
use App\Payments\Enums\PaymentStatus;
use App\Referrals\Models\Referral;
use App\Referrals\Models\ReferralCode;
use App\Referrals\Models\ReferralReward;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Hito 15 — Escenario E: recorrido completo de referidos, de punta a
 * punta REAL — A genera invitación -> B (Contact nuevo real) la usa ->
 * B hace su propio onboarding -> B recibe su Trial automático (Hito 15,
 * nunca manual) -> B paga -> confirmación real vía Filament -> reward
 * para A. Reutiliza los invariantes ya probados en H13
 * (ReferralConversationFlowTest) sin repetirlos — aquí se ejercitan
 * encadenados con Onboarding/Trial/Payment reales de H15.
 */
function commercialReferralMessage(Tenant $tenant, string $from, ?string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

it('A invites B via wa.me -> B pays their first membership -> A receives exactly one reward, with reward_days snapshot', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000, 'nequi_number' => '300-111-2222', 'referral_reward_days' => 3]);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001160001']);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);
    // A ya es un usuario activo (tiene TrainingAccess) — condición real para
    // que la recompensa pueda APLICARSE de inmediato (extendByDays() exige
    // una fila de TrainingAccess existente; sin ella, la recompensa queda
    // Pending por diseño de H13, caso no cubierto en este test).
    $referrerAccessBefore = app(\App\Training\Support\TrainingAccessAdministrationService::class)->grantAutomaticTrial($referrer, 5);
    $referrerExpiryBefore = $referrerAccessBefore->expires_at->copy();

    // B es un Contact totalmente nuevo (el recorrido de onboarding turno a
    // turno y la concesión de Trial automático ya están probados
    // exhaustivamente en otros archivos — Escenario A/AutomaticTrialProvisionerTest;
    // este test se enfoca en Referral+Payment+Reward encadenados). B decide
    // pagar directamente, sin pasar por Trial — un hallazgo real de este
    // hito (ver informe): un Contact con TrainingAccess=Trial recién
    // otorgado pero SIN ninguna WorkoutSession todavía siempre enruta a
    // Training (TrainingIntentClassifier::hasActiveAccessAwaitingFirstWorkout(),
    // Hito 8.1) — por eso, para que B pueda alcanzar Payments en el mismo
    // recorrido, paga ANTES de pedir entrenar, exactamente como ya cubre
    // ReferralConversationFlowTest (H13) para el caso "ya tuvo un Payment
    // confirmado".
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    // B llega vía el link wa.me (mensaje prellenado con el código embebido)
    // pidiendo pagar directamente -> la atribución se captura en
    // PreRoutingScreener (corre para TODO mensaje, antes de Router).
    commercialReferralMessage($tenant, '573001160002', "Hola! Quiero pagar con Nequi {$code->code}");

    $referred = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001160002')->sole();
    $referral = Referral::where('referred_contact_id', $referred->id)->sole();
    expect($referral->referrer_contact_id)->toBe($referrer->id);
    $payment = Payment::where('contact_id', $referred->id)->sole();
    expect($payment->status)->toBe(PaymentStatus::Pending);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'amount' => 50000, 'date' => now()->format('Y-m-d'), 'time' => null,
            'reference' => 'REF-B', 'entity' => 'Nequi', 'payer_name' => null, 'uncertain' => false,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);
    commercialReferralMessage($tenant, '573001160002', 'Pagué, ref REF-B');
    expect($payment->fresh()->status)->toBe(PaymentStatus::UnderReview);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    // Confirmación real vía Filament — la PRIMERA compra confirmada de B.
    Livewire::actingAs($superAdmin)->test(ListPayments::class)->callTableAction('confirm', $payment->fresh());

    expect($payment->fresh()->status)->toBe(PaymentStatus::Confirmed);
    expect(TrainingAccess::where('contact_id', $referred->id)->sole()->status)->toBe(\App\Training\Enums\TrainingAccessStatus::Active);

    // A recibe exactamente una recompensa, con reward_days congelado.
    $reward = ReferralReward::where('referral_id', $referral->id)->sole();
    expect($reward->reward_days)->toBe(3);
    expect(ReferralReward::count())->toBe(1);

    $referrerAccess = TrainingAccess::where('contact_id', $referrer->id)->sole();
    expect($reward->application_status)->toBe(\App\Referrals\Enums\ReferralRewardApplicationStatus::Applied);
    // Extendido en DÍAS desde el vencimiento vigente de A — nunca perdió lo
    // que ya tenía (mismo criterio ya probado en TrainingAccessAdministrationServiceTest).
    expect($referrerAccess->expires_at->diffInDays($referrerExpiryBefore, true))->toBeGreaterThan(2)->toBeLessThan(4);
});

it('self-referral is blocked — a Contact using their own code is never attributed to themselves', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001160003']);
    $code = ReferralCode::factory()->create(['contact_id' => $contact->id]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    commercialReferralMessage($tenant, '573001160003', "quiero entrenar {$code->code}");

    expect(Referral::where('referred_contact_id', $contact->id)->exists())->toBeFalse();
});

it('cross-tenant referral codes never attribute across Tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $referrerA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $codeA = ReferralCode::factory()->create(['contact_id' => $referrerA->id]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    // Un mensaje que contiene el código del Tenant A llega al Tenant B.
    commercialReferralMessage($tenantB, '573001160004', "quiero entrenar {$codeA->code}");

    $contactB = Contact::where('tenant_id', $tenantB->id)->where('customer_phone', '573001160004')->sole();
    expect(Referral::where('referred_contact_id', $contactB->id)->exists())->toBeFalse();
});

it('a referred Contact who already had a confirmed Payment before is never attributed, even with a valid code in their message', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);
    $existing = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001160005']);
    Payment::factory()->create(['contact_id' => $existing->id, 'status' => PaymentStatus::Confirmed]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    commercialReferralMessage($tenant, '573001160005', "Necesito hablar con alguien {$code->code}");

    expect(Referral::where('referred_contact_id', $existing->id)->exists())->toBeFalse();
});
