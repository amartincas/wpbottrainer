<?php

/**
 * Architectural guardrail (Hito 3): Core must never depend on domain-specific
 * code — neither the legacy ecommerce catalog nor any concrete Handler. This
 * is what keeps "Core provides mechanism, Domain provides content" true over
 * time instead of just being a comment.
 *
 * WhatsApp/AI integrations are legitimately Core-level infrastructure (see
 * docs/ARCHITECTURE.md) — this test is scoped to the actual boundary that
 * matters: Handlers (where business logic lives) and the legacy ecommerce
 * catalog, not every dependency Core happens to have.
 *
 * If this test ever fails, it means something under App\Core now imports a
 * domain-specific class — exactly the coupling the Router/ContextBuilder
 * design (Hitos 2-3) was built to prevent.
 */

arch('App\Core does not depend on Handlers (domain-specific business logic)')
    ->expect('App\Core')
    ->not->toUse('App\Handlers');

arch('App\Core does not depend on the Training domain (Hito 4)')
    ->expect('App\Core')
    ->not->toUse('App\Training');

arch('App\Core does not depend on the legacy ecommerce catalog')
    ->expect('App\Core')
    ->not->toUse([
        'App\Models\Product',
        'App\Models\ProductImage',
        'App\Services\Inventory',
    ]);

arch('App\Core\Messaging\ExecutionContext and IngestedMessage carry no domain-specific fields')
    ->expect(['App\Core\Messaging\ExecutionContext', 'App\Core\Messaging\IngestedMessage'])
    ->not->toUse([
        'App\Models\Product',
        'App\Models\Contact',
    ]);
