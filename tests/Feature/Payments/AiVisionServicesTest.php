<?php

use App\Services\AI\GeminiService;
use App\Services\AI\GrokService;
use App\Services\AI\OpenAIService;
use Illuminate\Support\Facades\Http;

/**
 * Hito 8: soporte de visión verificado empíricamente contra las APIs
 * reales de OpenAI y Grok con una key real y una imagen de prueba generada
 * en el momento (monto/referencia/fecha, leídos correctamente, HTTP 200) —
 * ver docs/DECISIONS.md. Gemini implementa el mismo contrato siguiendo el
 * formato documentado de su API, pero NO fue verificado con una key real
 * en este hito (ninguna estaba disponible) — estos tests solo prueban la
 * forma de la petición, no una respuesta real de Google.
 */

it('OpenAIService::analyzeImage() uses the vision-capable default model (gpt-4o-mini), verified empirically', function () {
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Monto: 50000']]]], 200)]);

    $service = new OpenAIService('fake-key', 'gpt-4o-mini');
    $result = $service->analyzeImage(base64_encode('fake'), 'image/jpeg', 'Extrae el monto');

    expect($result)->toBe('Monto: 50000');
    Http::assertSent(fn ($request) => data_get($request->data(), 'model') === 'gpt-4o-mini'
        && data_get($request->data(), 'messages.0.content.1.image_url.url') === 'data:image/jpeg;base64,'.base64_encode('fake'));
});

it('GrokService::analyzeImage() uses a vision-capable model, distinct from the cheap text-chat default, verified empirically', function () {
    Http::fake(['api.x.ai/*' => Http::response(['choices' => [['message' => ['content' => 'Monto: 50000']]]], 200)]);

    // El modelo de chat de este Tenant sería 'grok-build-0.1' (el más barato,
    // sin visión) — analyzeImage() nunca debe usarlo.
    $service = new GrokService('fake-key', 'grok-build-0.1');
    $result = $service->analyzeImage(base64_encode('fake'), 'image/jpeg', 'Extrae el monto');

    expect($result)->toBe('Monto: 50000');
    Http::assertSent(fn ($request) => data_get($request->data(), 'model') === 'grok-4.20-0309-non-reasoning');
});

it('GeminiService::analyzeImage() follows the documented multimodal request shape (not empirically verified)', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'Monto: 50000']]]]],
    ], 200)]);

    $service = new GeminiService('fake-key', 'gemini-2.5-flash');
    $result = $service->analyzeImage(base64_encode('fake'), 'image/jpeg', 'Extrae el monto');

    expect($result)->toBe('Monto: 50000');
    Http::assertSent(fn ($request) => data_get($request->data(), 'contents.0.parts.1.inline_data.mime_type') === 'image/jpeg');
});
