<?php

namespace App\Contracts;

interface AiServiceInterface
{
    /**
     * Get a response from the AI service.
     *
     * @param string $userMessage The user's message
     * @param string $systemPrompt The system prompt/instructions
     * @param array $history Chat history array of ['role' => 'user|assistant', 'content' => 'message']
     * @return string The AI response message content
     */
    public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string;

    /**
     * Hito 8 (Payments): analiza una imagen (ej. un comprobante de pago) y
     * devuelve la respuesta de texto cruda del modelo — el llamador (ej.
     * App\Payments\Support\ReceiptExtractionService) es quien interpreta
     * ese texto como JSON estructurado. Esta interfaz solo sabe "enviar una
     * imagen + una instrucción, recibir texto" — nunca decide qué hacer
     * con el resultado.
     *
     * Usa un modelo con capacidad de visión propio de cada proveedor,
     * independiente del modelo de chat configurado por el Tenant (elegido
     * ahí por costo, no por capacidad de visión) — ver implementaciones.
     *
     * @param string $base64Image Contenido de la imagen, codificado en base64 (sin el prefijo data:...)
     * @param string $mimeType Ej. "image/jpeg", "image/png"
     * @param string $prompt Instrucción de qué extraer/describir
     */
    public function analyzeImage(string $base64Image, string $mimeType, string $prompt): string;
}
