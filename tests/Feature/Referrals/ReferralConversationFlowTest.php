<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrainingProfile;
use App\Payments\Enums\PaymentStatus;
use App\Models\Payment;
use App\Referrals\Models\Referral;
use App\Referrals\Models\ReferralCode;
use App\Referrals\Models\ReferralReward;
use Illuminate\Support\Facades\Http;

/**
 * Cobertura end-to-end del flujo real de Referrals (Hito 13), vía el Job
 * real — mismo patrón que PaymentConversationFlowTest.php/
 * TrainingConversationFlowTest.php: prueba el enrutamiento real
 * (Ingest -> PreRoutingScreener -> Router -> Dispatcher/AppServiceProvider),
 * no solo las clases aisladas.
 */

function sendReferralTestMessage(Tenant $tenant, string $from, string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
});

/**
 * Mejora UX (ver docs/DECISIONS.md): con wa_display_phone_number
 * configurado, la invitación ya NO se envía como texto plano con la URL
 * cruda — se envía como un mensaje interactivo nativo `cta_url` (botón),
 * vía WhatsAppService::sendCtaUrlMessage(). El código y el mensaje
 * prellenado (?text=...) siguen viajando exactamente igual que antes,
 * ahora dentro de interactive.action.parameters.url.
 *
 * Corrección post-prueba manual (ver docs/DECISIONS.md): la URL del botón
 * es `https://wa.me/?text=...` — SIN número — para que WhatsApp deje
 * elegir a quién reenviárselo (Click to Chat sin destinatario). Un
 * wa.me/<número>?text=... abriría el chat DIRECTO con ese número, que es
 * exactamente lo que un hallazgo real de prueba manual detectó como
 * incorrecto para este caso de uso.
 */
it('gives a brand-new contact a native CTA URL button (not plain text) when wa_display_phone_number is configured', function () {
    $tenant = Tenant::factory()->create(['wa_display_phone_number' => '573009998877', 'name' => 'WpbotTrainer - Test']);

    sendReferralTestMessage($tenant, '573001112233', 'Dame mi código de referido');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001112233')->sole();
    $code = ReferralCode::where('contact_id', $contact->id)->sole();

    $expectedInvitationText = "Hola! Quiero unirme a {$tenant->name} 💪 {$code->code}";
    $expectedUrl = 'https://wa.me/?text='.rawurlencode($expectedInvitationText);

    Http::assertSent(function ($request) use ($code, $expectedUrl) {
        $data = $request->data();

        return ($data['type'] ?? null) === 'interactive'
            && data_get($data, 'interactive.type') === 'cta_url'
            && data_get($data, 'interactive.action.name') === 'cta_url'
            && data_get($data, 'interactive.action.parameters.display_text') === 'Invitar a un amigo'
            && data_get($data, 'interactive.action.parameters.url') === $expectedUrl
            && str_contains(data_get($data, 'interactive.body.text', ''), $code->code)
            // Nunca debe enviarse como texto plano cuando hay CTA.
            && ! array_key_exists('text', $data);
    });
});

it('keeps the button label within Meta\'s 20-character limit', function () {
    expect(mb_strlen('Invitar a un amigo'))->toBeLessThanOrEqual(20);
});

/**
 * Regresión explícita del hallazgo de prueba manual: la URL del CTA debe
 * ser exactamente el formato Click-to-Chat "sin destinatario" de Meta
 * (wa.me/?text=...) — nunca wa.me/<número del tenant>?text=..., que abre
 * el chat directo con ese número en vez de dejar elegir a quién
 * reenviárselo el referente.
 */
it('uses the "no recipient" wa.me Click-to-Chat format — never the tenant phone number — in the CTA URL', function () {
    $tenant = Tenant::factory()->create(['wa_display_phone_number' => '573113079583']);

    sendReferralTestMessage($tenant, '573001119999', 'mi código');

    Http::assertSent(function ($request) use ($tenant) {
        $url = data_get($request->data(), 'interactive.action.parameters.url', '');

        return str_contains($url, 'wa.me/?text=')
            && ! str_contains($url, 'wa.me/'.$tenant->wa_display_phone_number.'?text=');
    });
});

/**
 * Aislamiento por tenant: dos tenants distintos, cada uno con su propio
 * wa_display_phone_number configurado (usado solo como señal para decidir
 * si se muestra el CTA — YA NO forma parte de la URL), deben producir cada
 * uno una invitación con el CÓDIGO propio de SU contacto, nunca el del
 * otro tenant, y ninguna de las dos URLs debe contener ningún número de
 * teléfono de tenant.
 */
it('isolates each tenant\'s own referral code in the CTA URL, never mixing tenants, and never embeds any tenant phone number', function () {
    $tenantA = Tenant::factory()->create(['wa_display_phone_number' => '573001110001']);
    $tenantB = Tenant::factory()->create(['wa_display_phone_number' => '573002220002']);

    sendReferralTestMessage($tenantA, '573005550001', 'mi código');
    sendReferralTestMessage($tenantB, '573005550002', 'mi código');

    $contactA = Contact::where('tenant_id', $tenantA->id)->where('customer_phone', '573005550001')->sole();
    $codeA = ReferralCode::where('contact_id', $contactA->id)->sole();
    $contactB = Contact::where('tenant_id', $tenantB->id)->where('customer_phone', '573005550002')->sole();
    $codeB = ReferralCode::where('contact_id', $contactB->id)->sole();

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'interactive.action.parameters.url', ''), $codeA->code));
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'interactive.action.parameters.url', ''), $codeB->code));

    // Ninguna URL de ningún tenant contiene ningún número de teléfono de tenant.
    $allCtaUrls = collect(Http::recorded())
        ->map(fn ($pair) => data_get($pair[0]->data(), 'interactive.action.parameters.url'))
        ->filter();
    expect($allCtaUrls->contains(fn ($url) => str_contains($url, '573001110001') || str_contains($url, '573002220002')))->toBeFalse();
});

/**
 * URL encoding: el texto prellenado contiene espacios, "!", el emoji 💪 y
 * el código — todos deben seguir codificados exactamente como los produce
 * rawurlencode() (misma función ya usada antes de este cambio, sin
 * reconstrucción manual).
 */
it('keeps the exact rawurlencode() output for spaces, punctuation, emoji, and the referral code in the CTA URL', function () {
    $tenant = Tenant::factory()->create(['wa_display_phone_number' => '573009998877', 'name' => 'WpbotTrainer - Produccion']);

    sendReferralTestMessage($tenant, '573001112299', 'mi código');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001112299')->sole();
    $code = ReferralCode::where('contact_id', $contact->id)->sole();

    $expectedInvitationText = "Hola! Quiero unirme a {$tenant->name} 💪 {$code->code}";
    $expectedEncoded = rawurlencode($expectedInvitationText);

    Http::assertSent(function ($request) use ($expectedEncoded) {
        $url = data_get($request->data(), 'interactive.action.parameters.url', '');

        return str_ends_with($url, '?text='.$expectedEncoded);
    });
});

it('falls back to plain text (no CTA, no link) when the tenant has no wa_display_phone_number configured', function () {
    $tenant = Tenant::factory()->create(['wa_display_phone_number' => null]);

    sendReferralTestMessage($tenant, '573001112234', 'mi invitación');

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573001112234')->sole();
    $code = ReferralCode::where('contact_id', $contact->id)->sole();

    Http::assertSent(function ($request) use ($code) {
        $data = $request->data();

        return ($data['type'] ?? 'text') !== 'interactive'
            && ! isset($data['interactive'])
            && str_contains(data_get($data, 'text.body', ''), $code->code)
            && str_contains(data_get($data, 'text.body', ''), 'Quiero unirme a')
            && ! str_contains(data_get($data, 'text.body', ''), 'wa.me/');
    });
});

it('replies with the disabled message when the referral program is off for the tenant', function () {
    $tenant = Tenant::factory()->create(['referral_program_enabled' => false]);

    sendReferralTestMessage($tenant, '573001112235', 'mi código');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'no está disponible'));
});

it('tells a contact with zero referrals that they have not referred anyone yet', function () {
    $tenant = Tenant::factory()->create();

    sendReferralTestMessage($tenant, '573001112236', 'mis referidos');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'no has referido'));
});

it('reports accurate stats: referred count, rewarded count, and total days earned', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112237']);

    $referredWithReward = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $referral1 = Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referredWithReward->id]);
    ReferralReward::factory()->create(['referral_id' => $referral1->id, 'reward_days' => 3]);

    $referredNoReward = Contact::factory()->create(['tenant_id' => $tenant->id]);
    Referral::factory()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referredNoReward->id]);

    sendReferralTestMessage($tenant, '573001112237', 'cuántos referidos tengo');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Has referido a 2 personas')
        && str_contains(data_get($request->data(), 'text.body', ''), '1 ya activó')
        && str_contains(data_get($request->data(), 'text.body', ''), 'Has ganado 3 días'));
});

it('end-to-end: a first message with an embedded valid code creates the attribution AND normal onboarding continues unaffected', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'extracted' => [
                'name' => null, 'goal' => null, 'experience_level' => null,
                'primary_focus' => null, 'secondary_focus' => null, 'training_location' => null,
                'restrictions' => null, 'available_equipment' => null, 'equipment_fully_equipped' => null,
                'sessions_per_week' => null, 'age' => null, 'sex' => null, 'weight_kg' => null,
                'height_cm' => null, 'safety_signal_text' => null,
                'health_declaration_category' => null, 'health_condition_text' => null, 'functional_limitation_text' => null,
            ],
            'next_action' => 'ask_name',
            'response' => '¿Cómo te gustaría que te llame?',
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    // El mensaje reenviado desde wa.me: contiene el código Y una palabra
    // clave de Training — debe clasificar como Training normalmente, sin
    // que la atribución (PreRoutingScreener, corre antes) lo desvíe.
    sendReferralTestMessage($tenant, '573009990000', "Hola! Quiero entrenar 💪 {$code->code}");

    $invited = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573009990000')->sole();

    $referral = Referral::where('referred_contact_id', $invited->id)->sole();
    expect($referral->referrer_contact_id)->toBe($referrer->id);

    // El onboarding de Training siguió su curso normal — se creó el
    // TrainingProfile como en cualquier primer mensaje de Training.
    expect(TrainingProfile::where('contact_id', $invited->id)->exists())->toBeTrue();
});

it('end-to-end: a contact who already had a confirmed Payment is never attributed, even with a valid code in their message', function () {
    $tenant = Tenant::factory()->create(); // nequi_number viene por defecto de la factory
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = ReferralCode::factory()->create(['contact_id' => $referrer->id]);
    $existing = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573009991111']);
    Payment::factory()->create(['contact_id' => $existing->id, 'status' => PaymentStatus::Confirmed]);

    // "quiero pagar" enruta de forma determinista a PaymentHandler (sin IA)
    // — evita depender de FallbackChatHandler/OpenAI para este caso, que ya
    // no es lo que se prueba aquí (la elegibilidad ya está cubierta a nivel
    // unitario en ReferralAttributionPreRoutingScreenTest).
    sendReferralTestMessage($tenant, '573009991111', "Quiero pagar {$code->code}");

    expect(Referral::where('referred_contact_id', $existing->id)->exists())->toBeFalse();
});
