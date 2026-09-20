<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Models\Tenant;
use App\Referrals\Support\ReferralIntentClassifier;

/**
 * Precedencia de Intents (ver docs/DECISIONS.md) — ReferralIntentClassifier
 * es 100% explícito (solo keywords, sin ninguna consulta de estado — a
 * diferencia de Training/Payment, no tiene contraparte "Contextual"). Este
 * archivo no existía como suite dedicada; se crea aquí, sin eliminar la
 * cobertura ya existente en ReferralConversationFlowTest.php/
 * IntentPrecedenceTest.php (E2E vía el Router real).
 */
function makeReferralClassifierContext(Tenant $tenant, ?string $body): ExecutionContext
{
    return new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage('573001112233', $body, 'wamid.1', 'text', null),
    );
}

it('classifies existing keywords as Intent::Referral (regresión de cobertura previa)', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new ReferralIntentClassifier;

    expect($classifier->classify(makeReferralClassifierContext($tenant, 'Dame mi código de referido')))->toBe(Intent::Referral);
    expect($classifier->classify(makeReferralClassifierContext($tenant, 'Quiero invitar a un amigo')))->toBe(Intent::Referral);
    expect($classifier->classify(makeReferralClassifierContext($tenant, 'Quiero referir a alguien')))->toBe(Intent::Referral);
    expect($classifier->classify(makeReferralClassifierContext($tenant, 'Cuántos referidos tengo')))->toBe(Intent::Referral);
});

it('declines (returns null) for unrelated messages with no Referral signal', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new ReferralIntentClassifier;

    expect($classifier->classify(makeReferralClassifierContext($tenant, 'Hola, ¿cómo estás?')))->toBeNull();
    expect($classifier->classify(makeReferralClassifierContext($tenant, null)))->toBeNull();
});

/**
 * Hallazgo real de staging (ver docs/DECISIONS.md): "referenciar" es una
 * variante coloquial de "referir" en español colombiano que no coincidía
 * con ninguna keyword — el mensaje caía en Training contextual (Tier 2) en
 * vez de Referral. Se cubren las 3 frases específicas agregadas,
 * deliberadamente NO un "referenciar" suelto (ver el siguiente test).
 */
it('classifies the new "referenciar" phrasings as Intent::Referral', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new ReferralIntentClassifier;

    expect($classifier->classify(makeReferralClassifierContext($tenant, 'Quiero referenciar un amigo')))->toBe(Intent::Referral);
    expect($classifier->classify(makeReferralClassifierContext($tenant, 'Referenciar a un amigo')))->toBe(Intent::Referral);
    expect($classifier->classify(makeReferralClassifierContext($tenant, 'Quiero referenciar')))->toBe(Intent::Referral);
});

/**
 * Guardrail explícito: NO se agregó "referenciar" como keyword genérica
 * (substring independiente) — solo las 3 frases específicas de arriba.
 * Esto mantiene fuera de alcance, deliberadamente, la deuda semántica ya
 * conocida de "referir" (verbo aislado que no distingue comando de
 * pregunta informativa) — no se amplía esa misma ambigüedad a una segunda
 * palabra. Si este test empieza a fallar porque alguien agregó
 * "referenciar" suelto al array de KEYWORDS, es una señal de que se
 * reabrió esa deuda sin que fuera la decisión tomada aquí.
 */
it('does NOT recognize "referenciar" as a standalone/generic keyword — only the specific phrases added', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new ReferralIntentClassifier;

    // Frases que contienen "referenciar" pero NINGUNA de las 3 frases
    // específicas agregadas — no deben clasificar como Referral.
    expect($classifier->classify(makeReferralClassifierContext($tenant, '¿Puedo referenciar a alguien?')))->toBeNull();
    expect($classifier->classify(makeReferralClassifierContext($tenant, '¿Cómo funciona eso de referenciar?')))->toBeNull();
    expect($classifier->classify(makeReferralClassifierContext($tenant, 'No sé qué es referenciar')))->toBeNull();
});
