<?php

use App\Core\Messaging\ContextualIntentClassifierInterface;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\Tenant;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Support\PaymentContextualIntentClassifier;

/**
 * Precedencia de Intents (ver docs/DECISIONS.md) — extraído literalmente de
 * PaymentIntentClassifierTest.php: estos tests prueban la mitad CONTEXTUAL
 * de la clasificación de Payment (antes vivía en la misma clase que las
 * keywords explícitas). Ninguna condición ni aserción cambió de
 * significado — solo la clase bajo prueba.
 */
function makePaymentContextualClassifierContext(Tenant $tenant, ?string $body, string $from = '573001112233', string $type = 'text'): ExecutionContext
{
    return new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage($from, $body, 'wamid.1', $type, null),
    );
}

it('implements the contextual marker interface', function () {
    expect(new PaymentContextualIntentClassifier)->toBeInstanceOf(ContextualIntentClassifierInterface::class);
});

it('classifies as Payment for a contact with an open payment, even with no keywords or text (e.g. a bare image)', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending]);

    $classifier = new PaymentContextualIntentClassifier;

    expect($classifier->classify(makePaymentContextualClassifierContext($tenant, null, '573001112233', 'image')))->toBe(Intent::Payment);
});

it('does not force Payment for a contact whose payments are all closed', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Confirmed]);

    $classifier = new PaymentContextualIntentClassifier;

    expect($classifier->classify(makePaymentContextualClassifierContext($tenant, 'Hola', '573001112233')))->toBeNull();
});

it('declines for a contact that does not exist at all', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new PaymentContextualIntentClassifier;

    expect($classifier->classify(makePaymentContextualClassifierContext($tenant, 'algo cualquiera', '573009998877')))->toBeNull();
});
