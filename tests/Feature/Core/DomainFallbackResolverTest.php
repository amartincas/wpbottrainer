<?php

use App\Core\Messaging\DomainFallbackClaimInterface;
use App\Core\Messaging\DomainFallbackResolver;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Models\Tenant;

/**
 * Hito A (Entry/Domain Fallback) — DomainFallbackResolver debe ser
 * puramente mecánico, exactamente como Router/Dispatcher/PreRoutingScreener:
 * cero conocimiento de dominio, mapa de CLASES (no instancias) resuelto vía
 * Container, el primer Intent no-null gana, null si ninguno reclama.
 *
 * La real App\Training\Support\TrainingDomainFallbackClaim se cubre en
 * tests/Feature/Training/TrainingDomainFallbackClaimTest.php — este archivo
 * solo prueba el mecanismo genérico, mismo criterio que RouterTest.php.
 */

class DomainFallbackResolverTestAlwaysNullClaim implements DomainFallbackClaimInterface
{
    public function claim(ExecutionContext $context): ?Intent
    {
        return null;
    }
}

class DomainFallbackResolverTestFixedClaim implements DomainFallbackClaimInterface
{
    public static int $callCount = 0;

    public function claim(ExecutionContext $context): ?Intent
    {
        self::$callCount++;

        return Intent::Training;
    }
}

class DomainFallbackResolverTestAnotherFixedClaim implements DomainFallbackClaimInterface
{
    public static int $callCount = 0;

    public function claim(ExecutionContext $context): ?Intent
    {
        self::$callCount++;

        return Intent::Payment;
    }
}

beforeEach(function () {
    DomainFallbackResolverTestFixedClaim::$callCount = 0;
    DomainFallbackResolverTestAnotherFixedClaim::$callCount = 0;
});

function makeDomainFallbackTestContext(): ExecutionContext
{
    return new ExecutionContext(
        tenant: Tenant::factory()->create(),
        conversation: null,
        message: new IngestedMessage('573001112233', 'cualquier cosa', 'wamid.1', 'text', null),
    );
}

it('returns null when no claim is registered', function () {
    $resolver = new DomainFallbackResolver(app(), []);

    expect($resolver->resolve(makeDomainFallbackTestContext()))->toBeNull();
});

it('returns null when every registered claim declines', function () {
    $resolver = new DomainFallbackResolver(app(), [
        DomainFallbackResolverTestAlwaysNullClaim::class,
        DomainFallbackResolverTestAlwaysNullClaim::class,
    ]);

    expect($resolver->resolve(makeDomainFallbackTestContext()))->toBeNull();
});

it('returns the Intent from the first claim that resolves it', function () {
    $resolver = new DomainFallbackResolver(app(), [DomainFallbackResolverTestFixedClaim::class]);

    expect($resolver->resolve(makeDomainFallbackTestContext()))->toBe(Intent::Training);
});

it('tries claims in order and stops at the first non-null result', function () {
    $resolver = new DomainFallbackResolver(app(), [
        DomainFallbackResolverTestAlwaysNullClaim::class,
        DomainFallbackResolverTestFixedClaim::class,
        DomainFallbackResolverTestAnotherFixedClaim::class,
    ]);

    expect($resolver->resolve(makeDomainFallbackTestContext()))->toBe(Intent::Training);
    expect(DomainFallbackResolverTestFixedClaim::$callCount)->toBe(1);
    expect(DomainFallbackResolverTestAnotherFixedClaim::$callCount)->toBe(0);
});

it('resolves claims via the container, not by direct instantiation', function () {
    $resolver = new DomainFallbackResolver(app(), [DomainFallbackResolverTestFixedClaim::class]);

    $resolver->resolve(makeDomainFallbackTestContext());

    expect(DomainFallbackResolverTestFixedClaim::$callCount)->toBe(1);
});
