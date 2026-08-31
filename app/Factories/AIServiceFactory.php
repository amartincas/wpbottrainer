<?php

namespace App\Factories;

use App\Contracts\AiServiceInterface;
use App\Models\Tenant;
use App\Services\AI\GeminiService;
use App\Services\AI\GrokService;
use App\Services\AI\OpenAIService;
use Illuminate\Support\Facades\Log;

class AIServiceFactory
{
    /**
     * Supported models for each AI provider.
     *
     * Grok list actualizada en Hito 7A: 'grok-beta'/'grok-2'/'grok-3' ya no
     * existen en la API de x.ai (confirmado contra GET /v1/language-models
     * con una key real durante la preparación del E2E) — eran modelos
     * descontinuados. Reemplazados por los que esa misma consulta devolvió
     * como realmente disponibles (excluyendo 'grok-imagine-*', que son
     * modelos de imagen/video, no de chat). Ver docs/DECISIONS.md (D021).
     */
    private const SUPPORTED_MODELS = [
        'openai' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo'],
        'grok' => [
            'grok-4.20-0309-non-reasoning', 'grok-4.20-0309-reasoning', 'grok-4.20-multi-agent-0309',
            'grok-4.3', 'grok-4.5', 'grok-4.6', 'grok-build-0.1',
        ],
        'gemini' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-3-flash-preview'],
    ];

    /**
     * Default models for each AI provider.
     *
     * 'grok-build-0.1' es, de los modelos de chat disponibles, el más barato
     * por token (prompt/completion) según GET /v1/language-models al momento
     * de esta verificación — elegido como default por costo, a petición
     * explícita, no por ser el de mejor calidad conversacional. Si la
     * calidad de respuesta no es suficiente para el tono de entrenador
     * personal, cambiar Tenant.ai_model a 'grok-4.3'/'grok-4.5' es un simple
     * update de campo, sin tocar código.
     */
    private const DEFAULT_MODELS = [
        'openai' => 'gpt-4o-mini',
        'grok' => 'grok-build-0.1',
        'gemini' => 'gemini-2.5-flash',
    ];

    /**
     * Create an AI service instance based on the tenant's configuration.
     *
     * @param Tenant $tenant
     * @return AiServiceInterface
     * @throws \Exception
     */
    public static function make(Tenant $tenant): AiServiceInterface
    {
        $provider = $tenant->ai_provider;
        $model = $tenant->ai_model;
        $apiKey = $tenant->ai_api_key;

        // Check if API key is missing
        if (!$apiKey) {
            throw new \Exception("Missing API Key for tenant: {$tenant->name}");
        }

        // Validate and get the model, with fallback to default if invalid
        $model = self::validateAndGetModel($provider, $model, $tenant);

        return match ($provider) {
            'openai' => new OpenAIService($apiKey, $model),
            'grok' => new GrokService($apiKey, $model),
            'gemini' => new GeminiService($apiKey, $model),
            default => throw new \Exception("Unsupported AI provider: {$provider}"),
        };
    }

    /**
     * Validate the model and return it, or a fallback default model if invalid.
     *
     * @param string $provider
     * @param ?string $model
     * @param Tenant $tenant
     * @return string
     * @throws \Exception
     */
    private static function validateAndGetModel(string $provider, ?string $model, Tenant $tenant): string
    {
        // Check if provider is supported
        if (!isset(self::SUPPORTED_MODELS[$provider])) {
            throw new \Exception("Unsupported AI provider: {$provider}");
        }

        // If model is provided and valid, use it
        if ($model && in_array($model, self::SUPPORTED_MODELS[$provider], true)) {
            return $model;
        }

        // Model is missing or invalid - use default and log warning
        $defaultModel = self::DEFAULT_MODELS[$provider];
        
        Log::warning('AI model invalid or missing, using default', [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'provider' => $provider,
            'requested_model' => $model,
            'default_model' => $defaultModel,
            'supported_models' => self::SUPPORTED_MODELS[$provider],
        ]);

        return $defaultModel;
    }
}
