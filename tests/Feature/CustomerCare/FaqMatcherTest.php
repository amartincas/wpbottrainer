<?php

use App\CustomerCare\Models\Faq;
use App\CustomerCare\Support\FaqMatcher;
use App\Models\Tenant;

/**
 * Hito 14 — `FaqMatcher::retrieveCandidates()`: recuperación determinista
 * ("candidate retrieval"), nunca limitada por un `limit()` sobre el
 * catálogo crudo. `sanitize()`: validación backend en memoria, sin BD
 * adicional, contra el conjunto REALMENTE ofrecido a la IA.
 */
function faqMatcher(): FaqMatcher
{
    return new FaqMatcher;
}

it('retrieves a FAQ matching a term in "question"', function () {
    $tenant = Tenant::factory()->create();
    $faq = Faq::factory()->create(['tenant_id' => $tenant->id, 'question' => '¿Cuál es el horario de atención?', 'answer' => 'Respuesta.']);

    $candidates = faqMatcher()->retrieveCandidates($tenant, '¿cuál es el horario?');

    expect($candidates->pluck('id'))->toContain($faq->id);
});

it('retrieves a FAQ matching a term ONLY present in "answer", not in "question"', function () {
    $tenant = Tenant::factory()->create();
    $faq = Faq::factory()->create(['tenant_id' => $tenant->id, 'question' => '¿Precio?', 'answer' => 'La membresía mensual cuesta 50.000 COP.']);

    $candidates = faqMatcher()->retrieveCandidates($tenant, 'cuánto cuesta la membresía');

    expect($candidates->pluck('id'))->toContain($faq->id);
});

it('never returns an inactive FAQ, even if it matches every term', function () {
    $tenant = Tenant::factory()->create();
    Faq::factory()->inactive()->create(['tenant_id' => $tenant->id, 'question' => '¿Cuál es el horario?', 'answer' => 'Respuesta.']);

    $candidates = faqMatcher()->retrieveCandidates($tenant, '¿cuál es el horario?');

    expect($candidates)->toBeEmpty();
});

it('never returns a FAQ from another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    Faq::factory()->create(['tenant_id' => $tenantB->id, 'question' => '¿Cuál es el horario?', 'answer' => 'Respuesta.']);

    $candidates = faqMatcher()->retrieveCandidates($tenantA, '¿cuál es el horario?');

    expect($candidates)->toBeEmpty();
});

it('returns empty when the message has no significant terms — never falls back to the full catalog', function () {
    $tenant = Tenant::factory()->create();
    Faq::factory()->create(['tenant_id' => $tenant->id]);
    Faq::factory()->create(['tenant_id' => $tenant->id]);

    $candidates = faqMatcher()->retrieveCandidates($tenant, '???');

    expect($candidates)->toBeEmpty();
});

it('returns empty when no active FAQ shares any term with the message', function () {
    $tenant = Tenant::factory()->create();
    Faq::factory()->create(['tenant_id' => $tenant->id, 'question' => '¿Cuál es el horario?', 'answer' => 'Abrimos de 8 a 6.']);

    $candidates = faqMatcher()->retrieveCandidates($tenant, '¿puedo congelar mi membresía durante vacaciones?');

    expect($candidates)->toBeEmpty();
});

it('never depends on the total number of FAQs a tenant has — ranks by relevance and returns the top 5, not the first 5 created', function () {
    $tenant = Tenant::factory()->create();

    // 6 FAQs que comparten el término "membresía", con distinta cantidad
    // de coincidencias adicionales — la más relevante (más términos
    // compartidos) debe aparecer entre los candidatos devueltos aunque no
    // sea de las primeras creadas.
    for ($i = 0; $i < 6; $i++) {
        Faq::factory()->create([
            'tenant_id' => $tenant->id,
            'question' => "Pregunta genérica {$i} sobre membresía",
            'answer' => 'Respuesta genérica.',
        ]);
    }

    $mostRelevant = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => '¿Cuánto cuesta la membresía mensual y cómo pago la membresía?',
        'answer' => 'La membresía cuesta 50.000 COP mensuales, pago por membresía disponible.',
    ]);

    $candidates = faqMatcher()->retrieveCandidates($tenant, '¿cuánto cuesta la membresía?');

    expect($candidates)->toHaveCount(5); // top 5, nunca las 7 que matchean
    expect($candidates->pluck('id'))->toContain($mostRelevant->id);
    expect($candidates->first()->id)->toBe($mostRelevant->id); // la más relevante, primera
});

// ── Ronda 2 (piloto real), Cambio 6: vocabulario real de referidos ─────
// FaqMatcher NO se modifica (sigue siendo el mismo LIKE sobre términos
// significativos) — lo que cambia es el CONTENIDO de la FAQ, para que
// comparta término literal con lo que un usuario real escribe. Solo dentro
// de estos tests (factory) — el contenido real de staging queda fuera de
// alcance de esta implementación.

it('retrieves the referral FAQ for "puedo referir a alguien?" once its content explicitly includes the synonym "referir"', function () {
    $tenant = Tenant::factory()->create();
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => '¿Puedo recomendar o referir a mis amigos para que prueben la aplicación?',
        'answer' => 'Sí, puedes invitar a tus amigos. Escríbeme mi código y te doy tu enlace de invitación personal para compartir por WhatsApp.',
    ]);

    $candidates = faqMatcher()->retrieveCandidates($tenant, 'Puedo referir a alguien?');

    expect($candidates->pluck('id'))->toContain($faq->id);
});

it('retrieves the referral-benefit FAQ for "qué gano si refiero a alguien?" once its content explicitly includes the synonym "refiero"', function () {
    $tenant = Tenant::factory()->create();
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => '¿Qué beneficio obtengo si refiero a alguien y esa persona adquiere una suscripción?',
        'answer' => 'Cuando la persona a la que refiero realiza su primera compra confirmada, yo recibo días adicionales de acceso.',
    ]);

    $candidates = faqMatcher()->retrieveCandidates($tenant, '¿Qué gano si refiero a alguien?');

    expect($candidates->pluck('id'))->toContain($faq->id);
});

it('retrieves the referral FAQ for "dónde veo mi código de referido?" via the shared term "código"', function () {
    $tenant = Tenant::factory()->create();
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => '¿Puedo recomendar o referir a mis amigos para que prueben la aplicación?',
        'answer' => 'Sí, puedes invitar a tus amigos. Escríbeme mi código y te doy tu enlace de invitación personal.',
    ]);

    $candidates = faqMatcher()->retrieveCandidates($tenant, '¿Dónde veo mi código de referido?');

    expect($candidates->pluck('id'))->toContain($faq->id);
});

it('the referral FAQ is NOT retrieved for "quiero referir a alguien" with the ORIGINAL content (no synonym, no shared term at all) — proves the fix is the content, never FaqMatcher itself', function () {
    // Nota: "Puedo referir a alguien?" YA comparte el término "puedo" con
    // el contenido original ("¿Puedo recomendar..."), así que esa frase por
    // sí sola no demuestra el problema real — se usa aquí una formulación
    // sin NINGÚN término compartido con el contenido original, para aislar
    // genuinamente el efecto del cambio de contenido.
    $tenant = Tenant::factory()->create();
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => '¿Puedo recomendar a mis amigos para que prueben la aplicación?', // contenido original, sin "referir"
        'answer' => 'Sí, puedes invitar a tus amigos. Escríbeme mi código y te doy tu enlace de invitación personal para compartir por WhatsApp.',
    ]);

    $candidates = faqMatcher()->retrieveCandidates($tenant, 'quiero referir a alguien');

    expect($candidates)->toBeEmpty();
});

// ── sanitize() ────────────────────────────────────────────────────────

it('sanitize() leaves the result unchanged when faq_match_id is null', function () {
    $result = ['faq_match_id' => null, 'faq_response_text' => null];

    expect(faqMatcher()->sanitize($result, collect()))->toBe($result);
});

it('sanitize() leaves the result unchanged when faq_match_id belongs to the offered candidates', function () {
    $tenant = Tenant::factory()->create();
    $faq = Faq::factory()->create(['tenant_id' => $tenant->id]);
    $result = ['faq_match_id' => $faq->id, 'faq_response_text' => 'Redacción de la IA.', 'customer_service_needed' => false, 'customer_service_message' => null];

    $sanitized = faqMatcher()->sanitize($result, collect([$faq]));

    expect($sanitized)->toBe($result);
});

it('sanitize() discards EVERYTHING — including any customer_service_message already drafted by the AI — when faq_match_id is not among the offered candidates', function () {
    $tenant = Tenant::factory()->create();
    $offered = Faq::factory()->create(['tenant_id' => $tenant->id]);
    $notOffered = Faq::factory()->create(['tenant_id' => $tenant->id]); // FAQ real, pero NO estaba en la lista mostrada a la IA

    $result = [
        'faq_match_id' => $notOffered->id,
        'faq_response_text' => 'Respuesta que la IA generó igual, invalida.',
        'customer_service_needed' => false,
        'customer_service_message' => 'Un acuse de recibo que la IA también redactó.',
    ];

    $sanitized = faqMatcher()->sanitize($result, collect([$offered]));

    expect($sanitized['faq_match_id'])->toBeNull();
    expect($sanitized['faq_response_text'])->toBeNull();
    expect($sanitized['customer_service_needed'])->toBeTrue();
    expect($sanitized['customer_service_message'])->toBeNull(); // descartado, NUNCA conservado
});

it('sanitize() works identically against a plain array of CoachFaqCandidate-shaped objects (interruption path)', function () {
    $candidate = new App\Training\Context\CoachFaqCandidate(id: 5, question: 'q', answer: 'a');
    $result = ['faq_match_id' => 999, 'faq_response_text' => 'x', 'customer_service_needed' => false, 'customer_service_message' => null];

    $sanitized = faqMatcher()->sanitize($result, [$candidate]);

    expect($sanitized['faq_match_id'])->toBeNull();
    expect($sanitized['customer_service_needed'])->toBeTrue();
});
