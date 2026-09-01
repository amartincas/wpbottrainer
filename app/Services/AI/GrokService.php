<?php

namespace App\Services\AI;

use App\Contracts\AiServiceInterface;
use Illuminate\Support\Facades\Http;

class GrokService implements AiServiceInterface
{
    private string $apiKey;
    private string $model;

    /**
     * Modelo de visión (Hito 8) — verificado empíricamente con una key real
     * (imagen de prueba con monto/referencia/fecha, leída correctamente,
     * HTTP 200). Deliberadamente distinto del modelo de chat por defecto
     * ('grok-build-0.1', elegido por ser el más barato para texto, ver
     * AIServiceFactory) — ese modelo no tiene por qué soportar visión, así
     * que Payments nunca depende de Tenant.ai_model para esto.
     */
    private const VISION_MODEL = 'grok-4.20-0309-non-reasoning';

    public function __construct(string $apiKey, string $model)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function analyzeImage(string $base64Image, string $mimeType, string $prompt): string
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post('https://api.x.ai/v1/chat/completions', [
                    'model' => self::VISION_MODEL,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64Image}"]],
                        ],
                    ]],
                    'max_tokens' => 500,
                ]);

            if ($response->failed()) {
                throw new \Exception('X.ai vision error: '.$response->body());
            }

            return $response->json('choices.0.message.content') ?? '';
        } catch (\Exception $e) {
            throw new \Exception('Grok vision service error: '.$e->getMessage());
        }
    }

    /**
     * Get a response from X.ai (Grok) API.
     *
     * @param string $userMessage The user's message
     * @param string $systemPrompt The system prompt/instructions
     * @param array $history Chat history array of ['role' => 'user|assistant', 'content' => 'message']
     * @return string The AI response message content
     */
    public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
    {
        try {
            // Build messages array: system prompt + history + current message
            $messages = [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
            ];

            // Add chat history
            foreach ($history as $msg) {
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                ];
            }

            // Add current user message
            $messages[] = [
                'role' => 'user',
                'content' => $userMessage,
            ];

            $response = Http::withToken($this->apiKey)
                ->post('https://api.x.ai/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => 0.7,
                    'max_tokens' => 1024,
                ]);

            if ($response->failed()) {
                throw new \Exception('X.ai API error: ' . $response->body());
            }

            $data = $response->json();

            return $data['choices'][0]['message']['content'] ?? '';
        } catch (\Exception $e) {
            throw new \Exception('Grok service error: ' . $e->getMessage());
        }
    }

    /**
     * Test X.ai (Grok) API connectivity.
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        if (!$this->apiKey) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])->get('https://api.x.ai/v1/models');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
