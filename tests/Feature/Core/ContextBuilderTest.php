<?php

use App\Core\Memory\ContextBuilder;
use App\Core\Memory\ContextFragment;
use App\Core\Memory\ContextProviderInterface;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Models\Tenant;

/**
 * ContextBuilder (Hito 3): resolves only the providers a Handler explicitly
 * asks for, via the Container — the same resolution discipline as
 * App\Core\Messaging\Dispatcher. No real provider exists yet; these are
 * disposable test doubles that exist only to prove the mechanism works.
 */

class ContextBuilderTestProfileProvider implements ContextProviderInterface
{
    public static int $callCount = 0;

    public function provide(ExecutionContext $context): ContextFragment
    {
        self::$callCount++;

        return new ContextFragment(
            label: 'profile',
            data: ['goal' => 'test-goal'],
            source: 'db',
            confidence: 'confirmed',
        );
    }
}

class ContextBuilderTestActivityProvider implements ContextProviderInterface
{
    public static int $callCount = 0;

    public function provide(ExecutionContext $context): ContextFragment
    {
        self::$callCount++;

        return new ContextFragment(
            label: 'recent_activity',
            data: ['sessions_this_week' => 3],
            source: 'computed',
            confidence: 'system_recorded',
        );
    }
}

beforeEach(function () {
    ContextBuilderTestProfileProvider::$callCount = 0;
    ContextBuilderTestActivityProvider::$callCount = 0;
});

function makeTestExecutionContext(): ExecutionContext
{
    return new ExecutionContext(
        tenant: Tenant::factory()->create(),
        conversation: null,
        message: new IngestedMessage('573001112233', 'hola', 'wamid.1', 'text', null),
    );
}

it('resolves a requested provider via the container and returns its fragment', function () {
    $builder = new ContextBuilder(app(), [
        'profile' => ContextBuilderTestProfileProvider::class,
    ]);

    $fragments = $builder->build(makeTestExecutionContext(), ['profile']);

    expect($fragments)->toHaveCount(1);
    expect($fragments[0])->toBeInstanceOf(ContextFragment::class);
    expect($fragments[0]->label)->toBe('profile');
    expect($fragments[0]->data)->toBe(['goal' => 'test-goal']);
    expect($fragments[0]->source)->toBe('db');
    expect($fragments[0]->confidence)->toBe('confirmed');
    expect(ContextBuilderTestProfileProvider::$callCount)->toBe(1);
});

it('resolves multiple requested providers in the order requested', function () {
    $builder = new ContextBuilder(app(), [
        'profile' => ContextBuilderTestProfileProvider::class,
        'recent_activity' => ContextBuilderTestActivityProvider::class,
    ]);

    $fragments = $builder->build(makeTestExecutionContext(), ['recent_activity', 'profile']);

    expect($fragments)->toHaveCount(2);
    expect($fragments[0]->label)->toBe('recent_activity');
    expect($fragments[1]->label)->toBe('profile');
});

it('never invokes a provider that was not requested', function () {
    $builder = new ContextBuilder(app(), [
        'profile' => ContextBuilderTestProfileProvider::class,
        'recent_activity' => ContextBuilderTestActivityProvider::class,
    ]);

    $fragments = $builder->build(makeTestExecutionContext(), ['profile']);

    expect($fragments)->toHaveCount(1);
    expect(ContextBuilderTestProfileProvider::$callCount)->toBe(1);
    expect(ContextBuilderTestActivityProvider::$callCount)->toBe(0);
});

it('returns no fragments when no keys are requested', function () {
    $builder = new ContextBuilder(app(), [
        'profile' => ContextBuilderTestProfileProvider::class,
    ]);

    $fragments = $builder->build(makeTestExecutionContext(), []);

    expect($fragments)->toBe([]);
    expect(ContextBuilderTestProfileProvider::$callCount)->toBe(0);
});

it('throws a clear exception when a requested key has no provider registered', function () {
    $builder = new ContextBuilder(app(), []); // nothing registered

    expect(fn () => $builder->build(makeTestExecutionContext(), ['profile']))
        ->toThrow(RuntimeException::class, 'No context provider registered for key: profile');
});

it('builds a ContextFragment as a plain readonly-style value object', function () {
    $fragment = new ContextFragment(
        label: 'profile',
        data: ['goal' => 'lose weight'],
        source: 'db',
        confidence: 'confirmed',
    );

    expect($fragment->label)->toBe('profile');
    expect($fragment->data)->toBe(['goal' => 'lose weight']);
    expect($fragment->source)->toBe('db');
    expect($fragment->confidence)->toBe('confirmed');
});
