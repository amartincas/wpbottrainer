<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Models\Tenant;
use App\Payments\Support\PaymentIntentClassifier;

/**
 * Precedencia de Intents (ver docs/DECISIONS.md): PaymentIntentClassifier es
 * EXCLUSIVAMENTE explícito (solo keywords) desde la corrección de
 * precedencia — su parte contextual (Payment abierto sin resolver) se
 * extrajo a PaymentContextualIntentClassifier, ver
 * PaymentContextualIntentClassifierTest.php.
 */
function makePaymentClassifierContext(Tenant $tenant, ?string $body, string $from = '573001112233', string $type = 'text'): ExecutionContext
{
    return new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage($from, $body, 'wamid.1', $type, null),
    );
}

it('classifies a message with payment keywords as Intent::Payment', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new PaymentIntentClassifier;

    expect($classifier->classify(makePaymentClassifierContext($tenant, 'Quiero pagar')))->toBe(Intent::Payment);
    expect($classifier->classify(makePaymentClassifierContext($tenant, 'Te mando el comprobante de Nequi')))->toBe(Intent::Payment);
});

it('declines for unrelated messages with no keyword', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new PaymentIntentClassifier;

    expect($classifier->classify(makePaymentClassifierContext($tenant, 'Hola, ¿cómo estás?')))->toBeNull();
    expect($classifier->classify(makePaymentClassifierContext($tenant, null)))->toBeNull();
});

it('never consults Contact/state — declines for a bare image even with an open payment (moved to PaymentContextualIntentClassifier)', function () {
    // Regresión explícita de la extracción: antes de la corrección de
    // precedencia, este mismo caso clasificaba como Payment AQUÍ. Ahora
    // PaymentIntentClassifier ya no consulta Contact en absoluto — ver
    // PaymentContextualIntentClassifierTest.php para el comportamiento
    // contextual real (que se preserva sin cambios, solo en otra clase).
    $tenant = Tenant::factory()->create();
    $contact = \App\Models\Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    \App\Models\Payment::factory()->create(['contact_id' => $contact->id, 'status' => \App\Payments\Enums\PaymentStatus::Pending]);

    $classifier = new PaymentIntentClassifier;

    expect($classifier->classify(makePaymentClassifierContext($tenant, null, '573001112233', 'image')))->toBeNull();
});
