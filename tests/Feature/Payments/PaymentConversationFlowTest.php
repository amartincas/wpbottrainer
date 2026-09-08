<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AlertLog;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\User;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\MembershipPlan;
use Illuminate\Support\Facades\Http;

/**
 * End-to-end coverage del flujo real de Payments (Hito 8), vía el Job real
 * — mismo patrón que TrainingConversationFlowTest.php (Hito 5) y
 * SafetySignalPreRoutingScreenTest.php (Hito 7): prueba el enrutamiento
 * real (Router/Dispatcher/AppServiceProvider), no solo el Handler aislado.
 */

function sendPaymentTestMessage(Tenant $tenant, string $from, ?string $body, string $type = 'text', ?string $mediaId = null): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), $type, $mediaId);
    app()->call([$job, 'handle']);
}

it('shows the available payment options for a brand-new contact asking to pay', function () {
    $tenant = Tenant::factory()->create(); // nequi_number viene por defecto de la factory

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendPaymentTestMessage($tenant, '573001112233', 'Quiero pagar');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001112233')->first();
    expect($contact)->not->toBeNull();
    expect(Payment::where('contact_id', $contact->id)->exists())->toBeFalse(); // aún no eligió método

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Nequi'));
});

it('creates a pending Payment with instructions once the contact picks Nequi', function () {
    $tenant = Tenant::factory()->create(['monthly_price' => 50000, 'currency' => 'COP', 'nequi_number' => '300-111-2222']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendPaymentTestMessage($tenant, '573001112233', 'Quiero pagar con Nequi');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001112233')->first();
    $payment = Payment::where('contact_id', $contact->id)->first();

    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect((float) $payment->amount)->toBe(50000.0);
    expect($payment->method_label)->toBe('Nequi');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '300-111-2222')
        && ! str_contains(data_get($request->data(), 'text.body', ''), 'activado')); // nunca promete activación
});

it('moves the payment to under_review and alerts the super-admin when a text receipt is submitted', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending, 'amount' => 50000]);
    User::factory()->create(['is_super_admin' => true, 'phone' => '573009990000']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'amount' => 50000, 'date' => now()->format('Y-m-d'), 'time' => null,
            'reference' => '123456789', 'entity' => 'Nequi', 'payer_name' => null, 'uncertain' => false,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendPaymentTestMessage($tenant, '573001112233', 'Le mandé 50 mil por Nequi, referencia 123456789');

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::UnderReview);
    expect($fresh->validation_flags)->toBe([]);
    expect($fresh->receipt_submitted_at)->not->toBeNull();

    $receipt = PaymentReceipt::where('payment_id', $payment->id)->first();
    expect($receipt)->not->toBeNull();
    expect($receipt->source_type)->toBe('text');
    expect($receipt->file_path)->toBeNull();

    expect(AlertLog::where('category', 'payments')->exists())->toBeTrue();

    Http::assertSent(fn ($request) => ($request['to'] ?? null) === '573009990000'
        && str_contains($request['text']['body'] ?? '', 'Pago pendiente de verificación'));
});

it('downloads, persists, and hashes an image receipt, and extracts via the vision-capable model', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000, 'wa_phone_number_id' => '999888777']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending, 'amount' => 50000]);

    Http::fake([
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.com/receipt.jpg'], 200),
        'cdn.example.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'amount' => 50000, 'date' => now()->format('Y-m-d'), 'time' => null,
            'reference' => '999888777', 'entity' => 'Nequi', 'payer_name' => null, 'uncertain' => false,
        ])]]]], 200),
        'graph.facebook.com/v20.0/999888777/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendPaymentTestMessage($tenant, '573001112233', null, 'image', 'media123');

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::UnderReview);

    $receipt = PaymentReceipt::where('payment_id', $payment->id)->first();
    expect($receipt->source_type)->toBe('image');
    expect($receipt->file_path)->not->toBeNull();
    expect($receipt->file_hash)->toBe(hash('sha256', 'fake-image-bytes'));
    expect(\Illuminate\Support\Facades\Storage::disk('local')->exists($receipt->file_path))->toBeTrue();
});

it('does not create a receipt or change status for a message with no real payment signal, while a payment is pending', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'amount' => null, 'date' => null, 'time' => null, 'reference' => null,
            'entity' => null, 'payer_name' => null, 'uncertain' => true,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendPaymentTestMessage($tenant, '573001112233', 'ok, ya te aviso');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    expect(PaymentReceipt::where('payment_id', $payment->id)->exists())->toBeFalse();
});

it('reports the correct status in plain language for each Payment state', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Rejected, 'review_note' => 'Monto incorrecto']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendPaymentTestMessage($tenant, '573001112233', '¿cómo va mi pago?');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'rechazado')
        && str_contains(data_get($request->data(), 'text.body', ''), 'Monto incorrecto'));
});

it('never grants TrainingAccess from the conversational flow — only PaymentConfirmationService does, after human review', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending, 'amount' => 50000]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'amount' => 50000, 'date' => now()->format('Y-m-d'), 'time' => null,
            'reference' => '123', 'entity' => 'Nequi', 'payer_name' => null, 'uncertain' => false,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendPaymentTestMessage($tenant, '573001112233', 'Le mandé 50 mil por Nequi, ref 123');

    expect(TrainingAccess::where('contact_id', $contact->id)->exists())->toBeFalse();
});

// ── Selección de membresía (Hito 11) ────────────────────────────────────

it('auto-selects the plan and sends full instructions immediately when the Tenant has exactly ONE active MembershipPlan', function () {
    $tenant = Tenant::factory()->create(['nequi_number' => '300-111-2222']); // trae su plan "1 mes" único, por defecto de la factory

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendPaymentTestMessage($tenant, '573001112233', 'Quiero pagar con Nequi');

    $contact = Contact::where('tenant_id', $tenant->id)->first();
    $payment = Payment::where('contact_id', $contact->id)->first();

    expect($payment->needsPlanSelection())->toBeFalse();
    expect($payment->membership_months)->toBe(1);
    expect((float) $payment->amount)->toBe(50000.0);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '300-111-2222'));
});

it('asks which membership to activate when the Tenant has MULTIPLE active plans, and creates the Payment without membership_plan_id yet', function () {
    $tenant = Tenant::factory()->create(['nequi_number' => '300-111-2222']); // ya trae "1 mes"
    MembershipPlan::factory()->for($tenant, 'tenant')->months(3)->create(['price' => 130000]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendPaymentTestMessage($tenant, '573001112233', 'Quiero pagar con Nequi');

    $contact = Contact::where('tenant_id', $tenant->id)->first();
    $payment = Payment::where('contact_id', $contact->id)->first();

    expect($payment)->not->toBeNull();
    expect($payment->needsPlanSelection())->toBeTrue();
    expect($payment->amount)->toBeNull();
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'membresía'));
});

it('applies the plan selected by the user (by number of months), freezing amount/currency/membership_months on the Payment', function () {
    $tenant = Tenant::factory()->create(['nequi_number' => '300-111-2222']);
    MembershipPlan::factory()->for($tenant, 'tenant')->months(3)->create(['price' => 130000, 'label' => '3 meses']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendPaymentTestMessage($tenant, '573001112233', 'Quiero pagar con Nequi');
    $contact = Contact::where('tenant_id', $tenant->id)->first();

    sendPaymentTestMessage($tenant, '573001112233', '3 meses');

    $payment = Payment::where('contact_id', $contact->id)->first();
    expect($payment->needsPlanSelection())->toBeFalse();
    expect($payment->membership_months)->toBe(3);
    expect((float) $payment->amount)->toBe(130000.0);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '130.000'));
});

it('a plan that is NOT active can never be selected — an inactive plan is simply not recognized', function () {
    $tenant = Tenant::factory()->create(['nequi_number' => '300-111-2222']);
    MembershipPlan::factory()->for($tenant, 'tenant')->months(3)->inactive()->create(['label' => '3 meses (inactivo)']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendPaymentTestMessage($tenant, '573001112233', 'Quiero pagar con Nequi');
    $contact = Contact::where('tenant_id', $tenant->id)->first();

    // Solo hay UN plan activo (el "1 mes" que la factory ya creó) — la
    // auto-selección debe haber ocurrido de inmediato con ESE, nunca con
    // el inactivo. Intentar "3 meses" después no debería tener ningún
    // Payment esperando selección al que aplicarse.
    $payment = Payment::where('contact_id', $contact->id)->first();
    expect($payment->needsPlanSelection())->toBeFalse();
    expect($payment->membership_months)->toBe(1); // el único activo, nunca el de 3 meses inactivo
});

it('re-shows the plan menu when the reply does not match any active plan', function () {
    $tenant = Tenant::factory()->create(['nequi_number' => '300-111-2222']);
    MembershipPlan::factory()->for($tenant, 'tenant')->months(3)->create(['price' => 130000]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendPaymentTestMessage($tenant, '573001112233', 'Quiero pagar con Nequi');
    $contact = Contact::where('tenant_id', $tenant->id)->first();

    sendPaymentTestMessage($tenant, '573001112233', 'no sé, cuál me recomiendas');

    $payment = Payment::where('contact_id', $contact->id)->first();
    expect($payment->needsPlanSelection())->toBeTrue(); // sigue sin resolver
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'membresía'));
});

// ── D3 — comprobante duplicado (Hito 11) ────────────────────────────────

it('the SAME image receipt sent twice (two different WAMIDs) never creates a second PaymentReceipt, never re-calls the AI, and never emits a second alert', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000, 'wa_phone_number_id' => '999888777']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending, 'amount' => 50000]);

    Http::fake([
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.com/receipt.jpg'], 200),
        'cdn.example.com/*' => Http::response('same-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'amount' => 50000, 'date' => now()->format('Y-m-d'), 'time' => null,
            'reference' => '999888777', 'entity' => 'Nequi', 'payer_name' => null, 'uncertain' => false,
        ])]]]], 200),
        'graph.facebook.com/v20.0/999888777/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendPaymentTestMessage($tenant, '573001112233', null, 'image', 'media123');
    $openAiCallsAfterFirst = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.openai.com'))->count();
    $alertsAfterFirst = AlertLog::where('category', 'payments')->count();

    // Mismo contenido de archivo, un WAMID DISTINTO — un reenvío real del
    // usuario, no un reintento de Meta (eso ya lo bloquea el dedup de
    // WhatsAppController por su cuenta, más abajo en la pila).
    sendPaymentTestMessage($tenant, '573001112233', null, 'image', 'media123');

    expect(PaymentReceipt::where('payment_id', $payment->id)->count())->toBe(1);
    expect(collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.openai.com'))->count())->toBe($openAiCallsAfterFirst); // ninguna llamada de IA nueva
    expect(AlertLog::where('category', 'payments')->count())->toBe($alertsAfterFirst); // ninguna alerta nueva
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Ya recibí este comprobante'));
});

it('a DIFFERENT receipt image for the same Payment is processed normally, never blocked by the duplicate check', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000, 'wa_phone_number_id' => '999888777']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending, 'amount' => 50000]);

    Http::fake([
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.com/receipt.jpg'], 200),
        'cdn.example.com/*' => Http::sequence()
            ->push('primera-imagen-borrosa', 200, ['Content-Type' => 'image/jpeg'])
            ->push('segunda-imagen-mas-clara', 200, ['Content-Type' => 'image/jpeg']),
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'amount' => 50000, 'date' => now()->format('Y-m-d'), 'time' => null,
            'reference' => '999888777', 'entity' => 'Nequi', 'payer_name' => null, 'uncertain' => false,
        ])]]]], 200),
        'graph.facebook.com/v20.0/999888777/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendPaymentTestMessage($tenant, '573001112233', null, 'image', 'media123');
    sendPaymentTestMessage($tenant, '573001112233', null, 'image', 'media123');

    expect(PaymentReceipt::where('payment_id', $payment->id)->count())->toBe(2); // archivo distinto, hash distinto, se procesa
});
