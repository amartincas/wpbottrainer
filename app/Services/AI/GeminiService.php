<?php

namespace App\Services\AI;

use App\Contracts\AiServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService implements AiServiceInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    /**
     * Hito 8 (Payments) — a diferencia de OpenAI y Grok, esta implementación
     * NO fue verificada con una key real durante este hito (no había
     * ninguna credencial de Gemini configurada en ningún Tenant existente).
     * Sigue el formato documentado públicamente de la API de Gemini
     * (`inline_data` dentro de `parts`, mismo endpoint `generateContent` ya
     * usado por getResponse()) — debe confirmarse con una prueba real antes
     * de activar `ai_provider: gemini` para un Tenant que use comprobantes
     * de pago con imagen.
     */
    public function analyzeImage(string $base64Image, string $mimeType, string $prompt): string
    {
        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout(30)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent", [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                            ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Image]],
                        ],
                    ]],
                    'generationConfig' => ['maxOutputTokens' => 500],
                ]);

            if ($response->failed()) {
                $errorMessage = $response->json()['error']['message'] ?? $response->body();
                throw new \Exception('Gemini vision error: '.$errorMessage);
            }

            return $response->json('candidates.0.content.parts.0.text') ?? '';
        } catch (\Exception $e) {
            throw new \Exception('Gemini vision service error: '.$e->getMessage());
        }
    }

    /**
     * Get a response from Google Gemini API.
     *
     * @param string $userMessage The user's message
     * @param string $systemPrompt The system prompt/instructions
     * @param array $history Chat history array of ['role' => 'user|assistant', 'content' => 'message']
     * @return string The AI response message content
     */
    public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
    {
        try {
            $contents = [];

            foreach ($history as $msg) {
                $contents[] = [
                    'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $msg['content']]],
                ];
            }

            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $userMessage]],
            ];

            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 1024,
                ],
            ];

            if (!empty($systemPrompt)) {
                $payload['systemInstruction'] = [
                    'parts' => [['text' => $systemPrompt]],
                ];
            }

            $response = Http::withHeaders([
                'x-goog-api-key' => $this->apiKey,
                'Content-Type'   => 'application/json',
            ])
            ->timeout(30)  
            ->connectTimeout(10)
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent",
                $payload
            );

            if ($response->failed()) {
                $errorMessage = $response->json()['error']['message'] ?? $response->body();
                throw new \Exception('Gemini API error: ' . $errorMessage);
            }

            $data = $response->json();
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        } catch (\Exception $e) {
            throw new \Exception('Gemini service error: ' . $e->getMessage());
        }
    }

    public function testConnection(): bool
    {
        if (!$this->apiKey) return false;

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout(10)
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent",
                    [
                        'contents' => [['parts' => [['text' => 'test']]]],
                        'generationConfig' => ['maxOutputTokens' => 5]
                    ]
                );

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    } 
}
