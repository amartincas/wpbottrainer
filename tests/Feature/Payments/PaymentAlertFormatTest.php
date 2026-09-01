<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AlertLog;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Payments\Enums\PaymentStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Formato de la alerta de Payments al superadmin (ajuste post-E2E real):
 * corrige la inconsistencia real encontrada — la alerta mostraba
 * `now()->format('Y-m-d')` etiquetado como "Fecha", sin relación alguna con
 * el comprobante, contradiciendo el propio `validation_flags: date_unreadable`
 * que aparecía justo debajo. Ver docs/DECISIONS.md.
 */
function sendAlertFormatTestMessage(Tenant $tenant, string $from, ?string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

function fakePaymentExtraction(array $overrides = []): array
{
    return array_merge([
        'amount' => 50000, 'date' => '2026-08-20', 'time' => null,
        'reference' => '123456789', 'entity' => 'Nequi', 'payer_name' => null, 'uncertain' => false,
    ], $overrides);
}

it('shows the literal extracted date and never the current server date, and omits the warnings section when there are no flags', function () {
    Carbon::setTestNow('2026-08-25 10:00:00'); // dentro de los 15 días de "stale_receipt" respecto a 2026-08-20

    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending, 'amount' => 50000]);
    User::factory()->create(['is_super_admin' => true, 'phone' => '573009990000']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(
            fakePaymentExtraction() // fecha limpia, monto exacto, referencia nueva -> sin flags
        )]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendAlertFormatTestMessage($tenant, '573001112233', 'Le mandé 50 mil por Nequi, referencia 123456789, el 20 de agosto');

    $payment = Payment::where('contact_id', $contact->id)->first();
    expect($payment->validation_flags)->toBe([]);

    Http::assertSent(function ($request) {
        $body = $request['text']['body'] ?? '';

        return ($request['to'] ?? null) === '573009990000'
            && str_contains($body, 'Fecha extraída: 2026-08-20')
            && ! str_contains($body, 'Fecha extraída: 2026-08-25') // la fecha "actual" (now()) NUNCA debe aparecer como fecha extraída
            && ! str_contains($body, 'ADVERTENCIAS DE VALIDACIÓN'); // sin flags -> la sección se omite por completo
    });

    Carbon::setTestNow();
});

it('shows both the detected and the expected amount, and a human-readable warning when they mismatch', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573002223344']);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending, 'amount' => 50000, 'currency' => 'COP']);
    User::factory()->create(['is_super_admin' => true, 'phone' => '573009990000']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(
            fakePaymentExtraction(['amount' => 32000])
        )]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendAlertFormatTestMessage($tenant, '573002223344', 'Le mandé 32 mil por Nequi, referencia 123456789, el 20 de agosto');

    $payment = Payment::where('contact_id', $contact->id)->first();
    expect($payment->validation_flags)->toContain('amount_mismatch');

    Http::assertSent(function ($request) {
        $body = $request['text']['body'] ?? '';

        return ($request['to'] ?? null) === '573009990000'
            && str_contains($body, 'Monto detectado: 32.000 COP')
            && str_contains($body, 'Monto esperado: 50.000 COP')
            && str_contains($body, 'ADVERTENCIAS DE VALIDACIÓN')
            && str_contains($body, 'El monto detectado no coincide con el esperado.') // texto legible, no la clave técnica "amount_mismatch"
            && ! str_contains($body, 'amount_mismatch'); // la clave técnica cruda nunca debe llegar al texto de la alerta
    });
});

it('shows "no disponible" and a readable warning when the AI could not extract any date', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573003334455']);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending, 'amount' => 50000]);
    User::factory()->create(['is_super_admin' => true, 'phone' => '573009990000']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(
            fakePaymentExtraction(['date' => null])
        )]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendAlertFormatTestMessage($tenant, '573003334455', 'Le mandé 50 mil por Nequi, referencia 123456789');

    $payment = Payment::where('contact_id', $contact->id)->first();
    expect($payment->validation_flags)->toContain('date_unreadable');

    Http::assertSent(function ($request) {
        $body = $request['text']['body'] ?? '';

        return ($request['to'] ?? null) === '573009990000'
            && str_contains($body, 'Fecha extraída: no disponible')
            && str_contains($body, 'Fecha no pudo validarse automáticamente.');
    });
});

it('keeps the literal unparseable date extracted (e.g. "hoy") and flags it, without ever substituting today\'s date', function () {
    Carbon::setTestNow('2026-09-01 10:00:00');

    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'monthly_price' => 50000]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573004445566']);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending, 'amount' => 50000]);
    User::factory()->create(['is_super_admin' => true, 'phone' => '573009990000']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(
            fakePaymentExtraction(['date' => 'hoy'])
        )]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendAlertFormatTestMessage($tenant, '573004445566', 'Pagué 50 mil por Nequi, referencia 123456789, hoy');

    $payment = Payment::where('contact_id', $contact->id)->first();
    expect($payment->extracted_data['date'])->toBe('hoy');
    expect($payment->validation_flags)->toContain('date_unreadable');

    Http::assertSent(function ($request) {
        $body = $request['text']['body'] ?? '';

        return ($request['to'] ?? null) === '573009990000'
            && str_contains($body, 'Fecha extraída: hoy')
            && ! str_contains($body, 'Fecha extraída: 2026-09-01'); // now() jamás debe aparecer como si fuera la fecha del comprobante
    });

    Carbon::setTestNow();
});
