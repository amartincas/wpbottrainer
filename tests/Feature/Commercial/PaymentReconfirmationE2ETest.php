<?php

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\User;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Events\PaymentConfirmed;
use App\Payments\Support\PaymentConfirmationService;
use App\Training\Enums\TrainingAccessStatus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Hito 15 — Escenario D: reconfirmación/idempotencia, sobre un Payment
 * REAL nacido del flujo conversacional (no un fixture aislado) — nunca
 * "+1 mes, +1 mes".
 *
 * La PRIMERA confirmación pasa por la Action real de Filament (así la
 * ejecuta un superadmin). Un SEGUNDO clic literal sobre el mismo botón no
 * es representable en un test — Filament oculta la Action `confirm` en
 * cuanto el Payment deja de estar `isOpen()` (protección real de la propia
 * UI, verificada más abajo). El escenario de reintento real (webhook
 * duplicado, un segundo proceso corriendo la misma operación) no pasa por
 * esa verificación de visibilidad — invoca `PaymentConfirmationService::
 * confirm()` directamente, exactamente como ya lo hace
 * `PaymentConfirmationServiceTest` (D1, concurrencia real) — aquí se
 * ejercita sobre el MISMO Payment que nació del flujo conversacional.
 */
function commercialPaymentReconfirmationMessage(Tenant $tenant, string $from, ?string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

it('confirming the same Payment twice (real Filament Action, then a simulated retry) produces a single commercial effect — never a double extension', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000, 'nequi_number' => '300-111-2222']);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    commercialPaymentReconfirmationMessage($tenant, '573001130001', 'Quiero pagar con Nequi');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001130001')->sole();

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'amount' => 50000, 'date' => now()->format('Y-m-d'), 'time' => null,
            'reference' => 'REF-X', 'entity' => 'Nequi', 'payer_name' => null, 'uncertain' => false,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);
    commercialPaymentReconfirmationMessage($tenant, '573001130001', 'Ya pagué, referencia REF-X');

    $payment = Payment::where('contact_id', $contact->id)->sole();
    expect($payment->status)->toBe(PaymentStatus::UnderReview);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Event::fake([PaymentConfirmed::class]);

    // Primera confirmación real, vía la Action de Filament.
    Livewire::actingAs($superAdmin)
        ->test(ListPayments::class)
        ->callTableAction('confirm', $payment->fresh())
        ->assertHasNoTableActionErrors();

    $accessAfterFirst = TrainingAccess::where('contact_id', $contact->id)->sole();
    $expiryAfterFirst = $accessAfterFirst->expires_at->copy();
    $reviewedAtAfterFirst = $payment->fresh()->reviewed_at->copy();

    // La propia UI ya protege contra un segundo clic: la Action deja de
    // estar visible en cuanto el Payment ya no está isOpen().
    Livewire::actingAs($superAdmin)
        ->test(ListPayments::class)
        ->assertTableActionHidden('confirm', $payment->fresh());

    // Reintento real (webhook duplicado / proceso concurrente) — nunca pasa
    // por la Action de Filament, invoca el servicio directamente.
    app(PaymentConfirmationService::class)->confirm($payment->fresh(), $superAdmin);

    expect(TrainingAccess::where('contact_id', $contact->id)->count())->toBe(1); // nunca una segunda fila
    $accessAfterSecond = TrainingAccess::where('contact_id', $contact->id)->sole();
    expect($accessAfterSecond->expires_at->toDateTimeString())->toBe($expiryAfterFirst->toDateTimeString()); // NUNCA +1 mes +1 mes
    expect($payment->fresh()->reviewed_at->toDateTimeString())->toBe($reviewedAtAfterFirst->toDateTimeString()); // no se re-registró
    expect($accessAfterSecond->status)->toBe(TrainingAccessStatus::Active);
    Event::assertDispatchedTimes(PaymentConfirmed::class, 1); // nunca un segundo evento
});

it('a duplicate receipt IMAGE submitted twice for the same Payment (real webhook retry shape) never creates a second PaymentReceipt nor re-triggers AI', function () {
    // Complementa PaymentConversationFlowTest (que ya prueba el dedup por
    // hash de forma aislada) verificando el mismo comportamiento dentro del
    // recorrido comercial completo — mismo WAMID distinto en cada entrega,
    // como Meta realmente reintregaría.
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000, 'nequi_number' => '300-111-2222', 'wa_phone_number_id' => '999888001']);

    // UNA sola llamada a Http::fake() para todo el test — llamadas
    // repetidas NO reemplazan stubs anteriores para el mismo patrón (se
    // acumulan, gana el primero registrado), lección ya aprendida en H14:
    // un `graph.facebook.com/*` amplio registrado antes interceptaría
    // también la descarga de media más abajo.
    Http::fake([
        'graph.facebook.com/*/media123' => Http::response(['url' => 'https://cdn.example.com/receipt.jpg'], 200),
        'cdn.example.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'amount' => 50000, 'entity' => 'Nequi', 'reference' => 'DUPREF', 'date' => now()->format('Y-m-d'),
            'time' => null, 'payer_name' => null, 'uncertain' => false,
        ])]]]], 200),
        'graph.facebook.com/v20.0/999888001/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    commercialPaymentReconfirmationMessage($tenant, '573001130002', 'Quiero pagar con Nequi');
    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001130002')->sole();

    $job1 = new ProcessWhatsAppMessage($tenant, '573001130002', null, 'wamid.IMG1', 'image', 'media123');
    app()->call([$job1, 'handle']);
    // Mismo comprobante, WAMID distinto — exactamente la forma de un
    // reintento real de Meta.
    $job2 = new ProcessWhatsAppMessage($tenant, '573001130002', null, 'wamid.IMG2', 'image', 'media123');
    app()->call([$job2, 'handle']);

    expect(\App\Models\PaymentReceipt::where('payment_id', Payment::where('contact_id', $contact->id)->value('id'))->count())->toBe(1);
    $openAiCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.openai.com'));
    expect($openAiCalls)->toHaveCount(1);
});
