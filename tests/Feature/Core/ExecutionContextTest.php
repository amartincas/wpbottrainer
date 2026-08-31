<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Models\Conversation;
use App\Models\Tenant;

/**
 * ExecutionContext (Hito 3): the single object that now flows through
 * Router → Dispatcher → Handler. Deliberately minimal — this test locks down
 * its exact shape so nobody adds a property "just in case" later without it
 * being a visible, reviewable change.
 */

it('exposes exactly tenant, conversation, message and legacy', function () {
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::create([
        'tenant_id' => $tenant->id,
        'customer_phone' => '573001112233',
        'last_session_at' => now(),
    ]);
    $message = new IngestedMessage('573001112233', 'Hola', 'wamid.1', 'text', null);

    $context = new ExecutionContext(
        tenant: $tenant,
        conversation: $conversation,
        message: $message,
        legacy: ['product_context' => 7],
    );

    expect($context->tenant)->toBe($tenant);
    expect($context->conversation)->toBe($conversation);
    expect($context->message)->toBe($message);
    expect($context->legacy)->toBe(['product_context' => 7]);

    // Locks the shape: exactly these 4 public properties, nothing more.
    $properties = array_map(
        fn (ReflectionProperty $p) => $p->getName(),
        (new ReflectionClass($context))->getProperties(ReflectionProperty::IS_PUBLIC)
    );
    expect($properties)->toEqualCanonicalizing(['tenant', 'conversation', 'message', 'legacy']);
});

it('allows a null conversation and an empty legacy bag by default', function () {
    $tenant = Tenant::factory()->create();
    $message = new IngestedMessage('573001112233', 'Hola', 'wamid.1', 'text', null);

    $context = new ExecutionContext(tenant: $tenant, conversation: null, message: $message);

    expect($context->conversation)->toBeNull();
    expect($context->legacy)->toBe([]);
});
