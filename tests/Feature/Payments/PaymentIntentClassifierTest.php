<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\Tenant;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Support\PaymentIntentClassifier;

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

it('declines for unrelated messages with no open payment', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new PaymentIntentClassifier;

    expect($classifier->classify(makePaymentClassifierContext($tenant, 'Hola, ¿cómo estás?')))->toBeNull();
    expect($classifier->classify(makePaymentClassifierContext($tenant, null)))->toBeNull();
});

it('classifies as Payment for a contact with an open payment, even with no keywords or text (e.g. a bare image)', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending]);

    $classifier = new PaymentIntentClassifier;

    expect($classifier->classify(makePaymentClassifierContext($tenant, null, '573001112233', 'image')))->toBe(Intent::Payment);
});

it('does not force Payment for a contact whose payments are all closed', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Confirmed]);

    $classifier = new PaymentIntentClassifier;

    expect($classifier->classify(makePaymentClassifierContext($tenant, 'Hola', '573001112233')))->toBeNull();
});
