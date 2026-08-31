<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\PreRoutingScreenInterface;
use App\Core\Messaging\PreRoutingScreener;
use App\Models\Tenant;

/**
 * PreRoutingScreener (Hito 7): runs BEFORE Router, regardless of what Intent
 * the message would otherwise classify as. Same Container-resolved-map
 * pattern as Router — this file only proves the generic mechanism, zero
 * domain knowledge. The real
 * App\Training\Support\SafetySignalPreRoutingScreen is covered in
 * tests/Feature/Training/.
 */

class PreRoutingScreenerTestAlwaysDeclines implements PreRoutingScreenInterface
{
    public function screen(ExecutionContext $context): bool
    {
        return false;
    }
}

class PreRoutingScreenerTestAlwaysClaims implements PreRoutingScreenInterface
{
    public static int $callCount = 0;

    public function screen(ExecutionContext $context): bool
    {
        self::$callCount++;

        return true;
    }
}

beforeEach(function () {
    PreRoutingScreenerTestAlwaysClaims::$callCount = 0;
});

function makeScreenerTestContext(): ExecutionContext
{
    return new ExecutionContext(
        tenant: Tenant::factory()->create(),
        conversation: null,
        message: new IngestedMessage('573001112233', 'Hola', 'wamid.1', 'text', null),
    );
}

it('returns false when no screen is registered', function () {
    $screener = new PreRoutingScreener(app(), []);

    expect($screener->screen(makeScreenerTestContext()))->toBeFalse();
});

it('returns false when every registered screen declines', function () {
    $screener = new PreRoutingScreener(app(), [PreRoutingScreenerTestAlwaysDeclines::class]);

    expect($screener->screen(makeScreenerTestContext()))->toBeFalse();
});

it('returns true as soon as one screen claims the message', function () {
    $screener = new PreRoutingScreener(app(), [PreRoutingScreenerTestAlwaysClaims::class]);

    expect($screener->screen(makeScreenerTestContext()))->toBeTrue();
});

it('tries screens in order and stops at the first one that claims the message', function () {
    $screener = new PreRoutingScreener(app(), [
        PreRoutingScreenerTestAlwaysDeclines::class,
        PreRoutingScreenerTestAlwaysClaims::class,
    ]);

    expect($screener->screen(makeScreenerTestContext()))->toBeTrue();
    expect(PreRoutingScreenerTestAlwaysClaims::$callCount)->toBe(1);
});

it('resolves screens via the container, not by direct instantiation', function () {
    $screener = new PreRoutingScreener(app(), [PreRoutingScreenerTestAlwaysClaims::class]);

    $screener->screen(makeScreenerTestContext());

    expect(PreRoutingScreenerTestAlwaysClaims::$callCount)->toBe(1);
});
