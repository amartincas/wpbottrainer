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
use App\Referrals\Support\ReferralCodeGenerator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

/**
 * Mejora UX "Mensaje 1 vs Mensaje 2" (ver docs/DECISIONS.md) — helpers para
 * decodificar, en los tests, el contenido REAL de cada capa: la URL del
 * CTA de A contiene el Mensaje 1 (humano, codificado); el Mensaje 1
 * contiene, anidado y codificado a su vez, el enlace directo al bot con el
 * Mensaje 2 (técnico, con el código).
 */
function decodeCtaShareableMessage(string $ctaUrl): string
{
    return rawurldecode(Str::after($ctaUrl, 'https://wa.me/?text='));
}

function extractNestedBotLink(string $shareableMessage): string
{
    return Str::after($shareableMessage, '👉 Comienza aquí: ');
}

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
});

/**
 * Mejora UX (ver docs/DECISIONS.md): con wa_display_phone_number
 * configurado, la invitación ya NO se envía como texto plano con la URL
 * cruda — se envía como un mensaje interactivo nativo `cta_url` (botón),
 * vía WhatsAppService::sendCtaUrlMessage().
 *
 * "Mensaje 1 vs Mensaje 2" (ver docs/DECISIONS.md) — la URL del CTA de A
 * (`https://wa.me/?text=...`, Click to Chat SIN destinatario) contiene el
 * Mensaje 1: humano, con el nombre de A, explica qué es el tenant, y trae
 * ANIDADO un enlace DIRECTO al bot (`https://wa.me/<número>?text=...`) con
 * el Mensaje 2 técnico (el que el bot recibirá) — que es el único que
 * contiene el código. Es lo que A realmente COMPARTE al elegir
 * destinatarios: WhatsApp reenvía texto plano, sin ningún botón (los
 * botones interactivos nunca sobreviven un reenvío).
 */
it('gives a brand-new contact a native CTA URL button whose shared content includes their name, an explanation, and a direct bot link with the code', function () {
    $tenant = Tenant::factory()->create(['wa_display_phone_number' => '573113079583', 'name' => 'WpbotTrainer - Produccion']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Juan']);

    sendReferralTestMessage($tenant, '573001112233', 'Dame mi código de referido');

    $code = ReferralCode::where('contact_id', $contact->id)->sole();

    Http::assertSent(function ($request) use ($code, $tenant) {
        $data = $request->data();

        if (($data['type'] ?? null) !== 'interactive'
            || data_get($data, 'interactive.type') !== 'cta_url'
            || data_get($data, 'interactive.action.name') !== 'cta_url'
            || data_get($data, 'interactive.action.parameters.display_text') !== 'Invitar a un amigo'
            || array_key_exists('text', $data) // nunca texto plano cuando hay CTA
        ) {
            return false;
        }

        $ctaUrl = data_get($data, 'interactive.action.parameters.url', '');

        // Mensaje 1 (lo que A comparte): "no destinatario" + nombre + explicación.
        if (! str_starts_with($ctaUrl, 'https://wa.me/?text=')) {
            return false;
        }
        $shareableMessage = decodeCtaShareableMessage($ctaUrl);
        if (! str_contains($shareableMessage, 'Juan quiere invitarte a')
            || ! str_contains($shareableMessage, $tenant->name)
        ) {
            return false;
        }

        // Mensaje 2 anidado (lo que el bot recibirá): número real del
        // tenant + el código intacto.
        $botLink = extractNestedBotLink($shareableMessage);
        if (! str_starts_with($botLink, 'https://wa.me/'.$tenant->wa_display_phone_number.'?text=')) {
            return false;
        }
        $prefilledBotMessage = rawurldecode(Str::after($botLink, '?text='));

        return str_contains($prefilledBotMessage, $code->code)
            // Extraíble por el mecanismo REAL de detección, no solo str_contains.
            && (new ReferralCodeGenerator)->extractFromText($prefilledBotMessage) === $code->code;
    });
});

it('keeps the button label within Meta\'s 20-character limit', function () {
    expect(mb_strlen('Invitar a un amigo'))->toBeLessThanOrEqual(20);
});

/**
 * Fallback determinista de nombre (ver docs/DECISIONS.md): customer_name
 * NO está garantizado — cualquier Contact puede pedir su código sin haber
 * pasado por el onboarding de Training que lo asigna. Sin nombre, el
 * Mensaje 1 usa una frase impersonal, nunca un relleno como "null" o
 * "Un amigo".
 */
it('falls back to an impersonal intro in the shareable message when the referrer has no customer_name', function () {
    $tenant = Tenant::factory()->create(['wa_display_phone_number' => '573113079583']);
    Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112255', 'customer_name' => null]);

    sendReferralTestMessage($tenant, '573001112255', 'mi código');

    Http::assertSent(function ($request) {
        $shareableMessage = decodeCtaShareableMessage(data_get($request->data(), 'interactive.action.parameters.url', ''));

        return str_starts_with($shareableMessage, 'Hola! Te están invitando a')
            && ! str_contains($shareableMessage, 'null');
    });
});

/**
 * Aislamiento por tenant: dos tenants distintos, cada uno con su propio
 * wa_display_phone_number, deben producir cada uno un Mensaje 1 cuyo
 * enlace anidado apunta a SU PROPIO número, con el código de SU contacto
 * — nunca mezclados.
 */
it('isolates each tenant\'s own phone number and referral code in the nested bot link, never mixing tenants', function () {
    $tenantA = Tenant::factory()->create(['wa_display_phone_number' => '573001110001']);
    $tenantB = Tenant::factory()->create(['wa_display_phone_number' => '573002220002']);

    sendReferralTestMessage($tenantA, '573005550001', 'mi código');
    sendReferralTestMessage($tenantB, '573005550002', 'mi código');

    $contactA = Contact::where('tenant_id', $tenantA->id)->where('customer_phone', '573005550001')->sole();
    $codeA = ReferralCode::where('contact_id', $contactA->id)->sole();
    $contactB = Contact::where('tenant_id', $tenantB->id)->where('customer_phone', '573005550002')->sole();
    $codeB = ReferralCode::where('contact_id', $contactB->id)->sole();

    $botLinks = collect(Http::recorded())
        ->map(fn ($pair) => data_get($pair[0]->data(), 'interactive.action.parameters.url'))
        ->filter()
        ->map(fn ($ctaUrl) => extractNestedBotLink(decodeCtaShareableMessage($ctaUrl)));

    expect($botLinks->contains(fn ($link) => str_starts_with($link, 'https://wa.me/573001110001?text=') && str_contains(rawurldecode($link), $codeA->code)))->toBeTrue();
    expect($botLinks->contains(fn ($link) => str_starts_with($link, 'https://wa.me/573002220002?text=') && str_contains(rawurldecode($link), $codeB->code)))->toBeTrue();
    // Ningún enlace de A contiene el número o código de B, y viceversa.
    expect($botLinks->contains(fn ($link) => str_starts_with($link, 'https://wa.me/573001110001?text=') && str_contains(rawurldecode($link), $codeB->code)))->toBeFalse();
});

/**
 * URL encoding en las dos capas: el Mensaje 1 (con nombre, acentos, emoji)
 * codifica correctamente la URL exterior; el Mensaje 2 anidado (con el
 * código) codifica correctamente la URL interior — ambas con
 * rawurlencode(), sin reconstrucción manual.
 */
it('keeps the exact rawurlencode() output on both the outer (shareable) and nested (bot) URLs', function () {
    $tenant = Tenant::factory()->create(['wa_display_phone_number' => '573009998877', 'name' => 'WpbotTrainer - Produccion']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112299', 'customer_name' => 'José']);

    sendReferralTestMessage($tenant, '573001112299', 'mi código');

    $code = ReferralCode::where('contact_id', $contact->id)->sole();

    $expectedInvitationText = "Hola! Quiero unirme a {$tenant->name} 💪 {$code->code}";
    $expectedBotLink = 'https://wa.me/573009998877?text='.rawurlencode($expectedInvitationText);
    $expectedShareableMessage = "Hola! José quiere invitarte a {$tenant->name}, tu entrenador de ejercicios personalizado 💪\n\n👉 Comienza aquí: {$expectedBotLink}";
    $expectedCtaUrl = 'https://wa.me/?text='.rawurlencode($expectedShareableMessage);

    Http::assertSent(fn ($request) => data_get($request->data(), 'interactive.action.parameters.url') === $expectedCtaUrl);
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
