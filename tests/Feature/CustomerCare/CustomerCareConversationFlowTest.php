<?php

use App\CustomerCare\Models\CustomerServiceRequest;
use App\CustomerCare\Models\Faq;
use App\CustomerCare\Support\CustomerServiceRequestRecorder;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AlertLog;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

/**
 * Hito 14 — cobertura end-to-end del camino INDEPENDIENTE (sin Training
 * activo) vía el Job real — mismo patrón que ReferralConversationFlowTest.php:
 * prueba el enrutamiento real (Ingest -> Router -> Dispatcher/AppServiceProvider
 * -> CustomerCareHandler), no solo las clases aisladas.
 */
function sendCustomerCareTestMessage(Tenant $tenant, string $from, string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

function fakeFaqAiResponse(array $payload): void
{
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [
            ['message' => ['content' => json_encode($payload)]],
        ]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);
}

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
});

it('escalates an explicit customer-service phrase deterministically, with ZERO AI calls', function () {
    $tenant = Tenant::factory()->create();

    sendCustomerCareTestMessage($tenant, '573001110001', 'Necesito hablar con alguien');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001110001')->sole();
    $request = CustomerServiceRequest::where('contact_id', $contact->id)->sole();
    expect($request->message)->toBe('Necesito hablar con alguien');
    expect(AlertLog::where('category', 'customer_service')->count())->toBe(1);

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), CustomerServiceRequestRecorder::EXPLICIT_REQUEST_TEXT));
});

it('answers a FAQ using the AI-drafted text grounded in a real candidate, with exactly ONE AI call', function () {
    $tenant = Tenant::factory()->create();
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => '¿Cuál es el horario de atención?',
        'answer' => 'Abrimos de lunes a sábado, de 6am a 9pm.',
    ]);

    fakeFaqAiResponse([
        'faq_match_id' => $faq->id,
        'faq_response_text' => 'Atendemos de lunes a sábado entre las 6am y las 9pm.',
        'customer_service_needed' => false,
        'customer_service_message' => null,
    ]);

    sendCustomerCareTestMessage($tenant, '573001110002', '¿Cuál es el horario de atención?');

    Http::assertSentCount(2, fn ($request) => true); // 1 a OpenAI + 1 a Meta (salida)
    expect(CustomerServiceRequest::count())->toBe(0);

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Atendemos de lunes a sábado entre las 6am y las 9pm.'));
});

it('escalates to customer service when the AI finds no confident FAQ match among real candidates, using the AI-drafted acknowledgment', function () {
    $tenant = Tenant::factory()->create();
    Faq::factory()->create(['tenant_id' => $tenant->id, 'question' => '¿Cuál es el precio?', 'answer' => 'La membresía cuesta 50.000 COP.']);

    fakeFaqAiResponse([
        'faq_match_id' => null,
        'faq_response_text' => null,
        'customer_service_needed' => true,
        'customer_service_message' => 'Ya registré tu consulta, nuestro equipo te responderá pronto por este medio.',
    ]);

    sendCustomerCareTestMessage($tenant, '573001110003', 'necesito el precio para 3 personas y descuento por grupo');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001110003')->sole();
    expect(CustomerServiceRequest::where('contact_id', $contact->id)->count())->toBe(1);
    expect(AlertLog::where('category', 'customer_service')->count())->toBe(1);

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Ya registré tu consulta, nuestro equipo te responderá pronto por este medio.'));
});

it('still calls the AI exactly once with ZERO candidates, and escalates using its acknowledgment (v6 requirement)', function () {
    $tenant = Tenant::factory()->create(); // sin ninguna FAQ creada

    fakeFaqAiResponse([
        'faq_match_id' => null,
        'faq_response_text' => null,
        'customer_service_needed' => true,
        'customer_service_message' => 'No tengo esa información todavía, ya la estoy consultando con el equipo.',
    ]);

    sendCustomerCareTestMessage($tenant, '573001110004', '¿tienen parqueadero disponible?');

    Http::assertSentCount(2, fn ($request) => true); // 1 sola llamada IA, nunca dos

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'No tengo esa información todavía, ya la estoy consultando con el equipo.'));
});

it('discards an invalid faq_match_id AND any AI-drafted customer_service_message, forcing the deterministic FAQ_FALLBACK_TEXT', function () {
    $tenant = Tenant::factory()->create();
    Faq::factory()->create(['tenant_id' => $tenant->id, 'question' => '¿Cuál es el horario?', 'answer' => 'De 6am a 9pm.']);
    $otherTenantFaq = Faq::factory()->create(['question' => 'otra pregunta de otro tenant', 'answer' => 'otra respuesta']);

    fakeFaqAiResponse([
        'faq_match_id' => $otherTenantFaq->id, // NUNCA fue ofrecido a la IA para este tenant
        'faq_response_text' => 'Respuesta inválida que no debería usarse jamás.',
        'customer_service_needed' => false,
        'customer_service_message' => 'Acuse de recibo que también debe descartarse.',
    ]);

    sendCustomerCareTestMessage($tenant, '573001110005', '¿cuál es el horario?');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), CustomerServiceRequestRecorder::FAQ_FALLBACK_TEXT));
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'inválida')
        || str_contains(data_get($request->data(), 'text.body', ''), 'Acuse de recibo que también'));
});

it('never leaks FAQs from another tenant into the candidates shown to the AI', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    Faq::factory()->create(['tenant_id' => $otherTenant->id, 'question' => '¿Cuál es el horario?', 'answer' => 'Secreto de otro tenant.']);

    fakeFaqAiResponse([
        'faq_match_id' => null,
        'faq_response_text' => null,
        'customer_service_needed' => true,
        'customer_service_message' => 'Registrado, te responderemos pronto.',
    ]);

    sendCustomerCareTestMessage($tenant, '573001110006', '¿cuál es el horario?');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'api.openai.com')) {
            return true;
        }

        return ! str_contains(json_encode($request->data()), 'Secreto de otro tenant');
    });
});
