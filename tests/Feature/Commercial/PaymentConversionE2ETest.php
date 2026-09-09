<?php

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AlertLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\User;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Events\PaymentConfirmed;
use App\Training\Enums\TrainingAccessStatus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Hito 15 — Escenario C: recorrido completo de conversión a pago, de punta
 * a punta REAL — "quiero pagar" -> plan -> comprobante -> under_review ->
 * Action `confirm` REAL de Filament (vía Livewire, no llamando al servicio
 * directo) -> TrainingAccess=Active -> CustomerNotifier. Cierra el hueco de
 * cobertura identificado en el diseño: ningún test previo unía estos dos
 * tramos en un solo recorrido, ni ejercitaba la Action de Filament en sí.
 */
function commercialPaymentMessage(Tenant $tenant, string $from, ?string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

it('a user pays end-to-end: "quiero pagar" -> plan -> comprobante -> under_review -> confirmación real vía Filament -> TrainingAccess Active + notificación', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000, 'currency' => 'COP', 'nequi_number' => '300-111-2222']);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    // 1. "quiero pagar" -> plan único, auto-seleccionado -> Payment(Pending)
    // con snapshot ya congelado (monto/plan/duración).
    commercialPaymentMessage($tenant, '573001120001', 'Quiero pagar con Nequi');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001120001')->sole();
    $payment = Payment::where('contact_id', $contact->id)->sole();
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect((float) $payment->amount)->toBe(50000.0);
    expect($payment->membership_months)->toBe(1);

    // 2. Comprobante -> under_review (monto y referencia coinciden).
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'amount' => 50000, 'date' => now()->format('Y-m-d'), 'time' => null,
            'reference' => 'REF-001', 'entity' => 'Nequi', 'payer_name' => null, 'uncertain' => false,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);
    commercialPaymentMessage($tenant, '573001120001', 'Ya pagué 50 mil, referencia REF-001');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::UnderReview);
    expect($payment->validation_flags)->toBe([]); // monto/referencia correctos, duración correcta ya congelada
    expect(PaymentReceipt::where('payment_id', $payment->id)->exists())->toBeTrue();
    expect(TrainingAccess::where('contact_id', $contact->id)->exists())->toBeFalse(); // NUNCA desde el flujo conversacional

    // 3. Confirmación REAL vía Filament (Action `confirm`, no el servicio
    // llamado directo) — así es como un superadmin la ejecuta en la
    // práctica.
    // Nota: `Conversation.last_session_at` solo lo actualiza el controller
    // real del webhook (WhatsAppController), nunca el Job invocado
    // directamente como en este test — se crea explícitamente para que la
    // ventana de 24h esté abierta y CustomerNotifier use mensaje libre
    // (mismo patrón que PaymentConfirmationServiceTest).
    Conversation::create(['tenant_id' => $tenant->id, 'customer_phone' => $contact->customer_phone, 'last_session_at' => now()->subMinutes(5)]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Event::fake([PaymentConfirmed::class]);

    Livewire::actingAs($superAdmin)
        ->test(ListPayments::class)
        ->callTableAction('confirm', $payment, data: ['note' => 'Comprobante verificado manualmente'])
        ->assertHasNoTableActionErrors();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Confirmed);
    expect($payment->reviewed_by)->toBe($superAdmin->id);
    expect($payment->review_note)->toBe('Comprobante verificado manualmente');

    // 4. TrainingAccess = Active, monto/duración exactos, snapshot correcto.
    $access = TrainingAccess::where('contact_id', $contact->id)->sole();
    expect($access->status)->toBe(TrainingAccessStatus::Active);
    expect($access->payment_id)->toBe($payment->id);
    expect($access->expires_at->diffInDays(now(), true))->toBeGreaterThan(27)->toBeLessThan(32); // 1 mes

    // 5. Evento + notificación al cliente.
    Event::assertDispatchedTimes(PaymentConfirmed::class, 1);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'confirmado'));
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '¿Quieres que te prepare tu entrenamiento?'));
});

it('rejects a payment end-to-end via the real Filament Action, with a reason, never touching TrainingAccess', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000, 'nequi_number' => '300-111-2222']);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview, 'amount' => 50000]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    Livewire::actingAs($superAdmin)
        ->test(ListPayments::class)
        ->callTableAction('reject', $payment, data: ['reason' => 'Monto no coincide'])
        ->assertHasNoTableActionErrors();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Rejected);
    expect(TrainingAccess::where('contact_id', $contact->id)->exists())->toBeFalse();
});

it('a non-super-admin cannot see or execute the confirm/reject actions in Filament', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenant->id]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);

    Livewire::actingAs($admin)
        ->test(ListPayments::class)
        ->assertTableActionHidden('confirm', $payment)
        ->assertTableActionHidden('reject', $payment);
});

it('confirming a Payment that came from a Contact with an admin-granted Trial correctly OVERWRITES to Active, extending from the Trial expiry — never losing days', function () {
    // Verifica que Escenario A (Trial) y Escenario C (Payment) componen
    // correctamente entre sí — un usuario que ya tenía Trial y decide pagar
    // no pierde los días de Trial que le quedaban.
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000]);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $trialAccess = app(\App\Training\Support\TrainingAccessAdministrationService::class)->grantAutomaticTrial($contact, 5);
    $trialExpiry = $trialAccess->expires_at->copy();
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview, 'amount' => 50000, 'membership_months' => 1]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    Livewire::actingAs($superAdmin)
        ->test(ListPayments::class)
        ->callTableAction('confirm', $payment)
        ->assertHasNoTableActionErrors();

    $access = TrainingAccess::where('contact_id', $contact->id)->sole();
    expect($access->status)->toBe(TrainingAccessStatus::Active);
    // Se extiende DESDE el vencimiento del Trial vigente (nunca desde ahora
    // — mismo criterio ya probado en PaymentConfirmationServiceTest, aquí
    // verificado específicamente sobre un Trial AUTOMÁTICO de Hito 15).
    expect($access->expires_at->greaterThan($trialExpiry))->toBeTrue();
    expect($access->expires_at->diffInDays($trialExpiry, true))->toBeGreaterThan(27)->toBeLessThan(32);
});
