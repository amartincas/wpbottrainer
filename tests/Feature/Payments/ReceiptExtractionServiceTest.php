<?php

use App\Models\Tenant;
use App\Payments\Support\ReceiptExtractionService;
use Illuminate\Support\Facades\Http;

it('extracts structured fields from a text description via the tenant chat provider', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'amount' => 50000, 'date' => '2026-09-01', 'time' => null,
                'reference' => '123456789', 'entity' => 'Nequi', 'payer_name' => null, 'uncertain' => false,
            ])]]],
        ], 200),
    ]);

    $result = (new ReceiptExtractionService)->extractFromText('le mandé 50 mil por Nequi, ref 123456789', $tenant);

    expect($result['amount'])->toBe(50000.0);
    expect($result['reference'])->toBe('123456789');
    expect($result['entity'])->toBe('Nequi');
    expect($result['uncertain'])->toBeFalse();
});

it('extracts structured fields from an image via the vision-capable model', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'amount' => 50000, 'date' => '2026-09-01', 'time' => '10:00',
                'reference' => '987654321', 'entity' => 'Daviplata', 'payer_name' => 'Juan Pérez', 'uncertain' => false,
            ])]]],
        ], 200),
    ]);

    $result = (new ReceiptExtractionService)->extractFromImage(base64_encode('fake-image-bytes'), 'image/jpeg', $tenant);

    expect($result['amount'])->toBe(50000.0);
    expect($result['payer_name'])->toBe('Juan Pérez');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com')
        && data_get($request->data(), 'model') === 'gpt-4o-mini');
});

it('never invents a value — an unreadable field stays null', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'amount' => null, 'date' => null, 'time' => null,
                'reference' => null, 'entity' => null, 'payer_name' => null, 'uncertain' => true,
            ])]]],
        ], 200),
    ]);

    $result = (new ReceiptExtractionService)->extractFromText('imagen borrosa', $tenant);

    expect($result['amount'])->toBeNull();
    expect($result['reference'])->toBeNull();
    expect($result['uncertain'])->toBeTrue();
});

it('returns an empty, uncertain result when the AI response is not valid JSON, instead of crashing', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(
            ['choices' => [['message' => ['content' => 'esto no es JSON']]]],
            200
        ),
    ]);

    $result = (new ReceiptExtractionService)->extractFromText('algo', $tenant);

    expect($result['amount'])->toBeNull();
    expect($result['uncertain'])->toBeTrue();
});

it('returns an empty result when the AI provider errors, instead of throwing', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    Http::fake(['api.openai.com/*' => Http::response('server error', 500)]);

    $result = (new ReceiptExtractionService)->extractFromImage(base64_encode('x'), 'image/jpeg', $tenant);

    expect($result['amount'])->toBeNull();
    expect($result['uncertain'])->toBeTrue();
});

it('returns an empty result for blank text without calling the AI at all', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    Http::fake();

    $result = (new ReceiptExtractionService)->extractFromText('', $tenant);

    expect($result['amount'])->toBeNull();
    Http::assertNothingSent();
});
