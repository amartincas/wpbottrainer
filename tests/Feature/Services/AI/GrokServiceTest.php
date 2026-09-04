<?php

use App\Services\AI\GrokService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Hito 9.3 (curación de seguridad) — hallazgo real durante la curación
 * manual de los 53 candidatos: `getResponse()` (usado por
 * `ExerciseSpanishContentGenerator` para generar name_es/instructions_es/
 * important_points_es) no fijaba ningún `->timeout()`, así que heredaba el
 * default de Laravel (30s, ver `PendingRequest.php`) — insuficiente para
 * una traducción de varios pasos, y sin ningún reintento ante un timeout
 * transitorio de la API de x.ai/Grok. `analyzeImage()` (Payments) ya tenía
 * su propio `->timeout(30)` deliberado para visión y no se tocó aquí.
 */
function fakeGrokChatResponse(array $payload): void
{
    Http::fake([
        'api.x.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload)]]],
        ], 200),
    ]);
}

it('getResponse() succeeds normally when x.ai responds without any transient failure', function () {
    fakeGrokChatResponse(['ok' => true]);

    $service = new GrokService('fake-key', 'grok-build-0.1');
    $result = $service->getResponse('hola', 'eres un asistente', []);

    expect($result)->toBe(json_encode(['ok' => true]));
    Http::assertSentCount(1);
});

it('getResponse() retries after a transient connection failure and succeeds on a later attempt, instead of failing on the first timeout', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received');
        }

        return Http::response([
            'choices' => [['message' => ['content' => json_encode(['ok' => true])]]],
        ], 200);
    });

    $service = new GrokService('fake-key', 'grok-build-0.1');
    $result = $service->getResponse('hola', 'eres un asistente', []);

    expect($result)->toBe(json_encode(['ok' => true]));
    expect($attempts)->toBe(2);
});

it('getResponse() gives up with a clear "Grok service error" after exhausting all retries on repeated timeouts', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received');
    });

    $service = new GrokService('fake-key', 'grok-build-0.1');

    expect(fn () => $service->getResponse('hola', 'eres un asistente', []))
        ->toThrow(Exception::class, 'Grok service error');
});
