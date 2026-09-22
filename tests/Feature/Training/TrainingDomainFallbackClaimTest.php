<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Models\Tenant;
use App\Training\Support\TrainingDomainFallbackClaim;

/**
 * Hito A (Entry/Domain Fallback) — TrainingDomainFallbackClaim lee
 * ÚNICAMENTE $context->tenant->primary_domain, ya cargado en ExecutionContext
 * (ninguna consulta adicional): 'training' reclama Intent::Training,
 * cualquier otro valor (incluido null) no reclama nada.
 */
function makeClaimTestContext(?string $primaryDomain): ExecutionContext
{
    return new ExecutionContext(
        tenant: Tenant::factory()->create(['primary_domain' => $primaryDomain]),
        conversation: null,
        message: new IngestedMessage('573001112233', 'mensaje no clasificable', 'wamid.1', 'text', null),
    );
}

it('claims Intent::Training when Tenant.primary_domain is training', function () {
    $claim = new TrainingDomainFallbackClaim;

    expect($claim->claim(makeClaimTestContext('training')))->toBe(Intent::Training);
});

it('does not claim anything when Tenant.primary_domain is null', function () {
    $claim = new TrainingDomainFallbackClaim;

    expect($claim->claim(makeClaimTestContext(null)))->toBeNull();
});

it('does not claim anything for any other Tenant.primary_domain value', function () {
    $claim = new TrainingDomainFallbackClaim;

    expect($claim->claim(makeClaimTestContext('ecommerce')))->toBeNull();
    expect($claim->claim(makeClaimTestContext('something_else')))->toBeNull();
});
