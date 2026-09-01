<?php

namespace App\Payments\Support;

use App\Factories\AIServiceFactory;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Extract half del flujo de comprobantes (Hito 8) — tercer uso del patrón
 * Extract→Decide→Narrate en este proyecto (después de Onboarding en Hito 5
 * y Reporte de Ejecución en Hito 6). La IA SOLO extrae lo visible en el
 * comprobante — nunca decide si el pago es válido, nunca compara contra el
 * precio del Tenant, nunca confirma nada. Esa decisión es de
 * App\Payments\Support\PaymentValidationService (código determinista) y,
 * al final, de un humano — nunca de este servicio ni del modelo.
 *
 * Todo campo no legible se devuelve `null` — nunca se inventa un valor
 * (mismo principio ya aplicado en ExecutionReportService).
 */
class ReceiptExtractionService
{
    private const EMPTY_RESULT = [
        'amount' => null,
        'date' => null,
        'time' => null,
        'reference' => null,
        'entity' => null,
        'payer_name' => null,
        'uncertain' => true,
    ];

    public function extractFromImage(string $base64Image, string $mimeType, Tenant $tenant): array
    {
        try {
            $ai = AIServiceFactory::make($tenant);
            $raw = $ai->analyzeImage($base64Image, $mimeType, $this->buildPrompt());

            return $this->parseJson($raw);
        } catch (\Throwable $e) {
            Log::warning('PAYMENT_RECEIPT_EXTRACTION_ERROR', ['error' => $e->getMessage()]);

            return self::EMPTY_RESULT;
        }
    }

    /**
     * Para un comprobante descrito en texto ("le mandé 50 mil por Nequi,
     * referencia 123456") — reutiliza el mismo contrato de salida que
     * extractFromImage(), pero vía el canal de texto normal del proveedor.
     */
    public function extractFromText(string $text, Tenant $tenant): array
    {
        if (trim($text) === '') {
            return self::EMPTY_RESULT;
        }

        try {
            $ai = AIServiceFactory::make($tenant);
            $raw = $ai->getResponse($text, $this->buildPrompt(), []);

            return $this->parseJson($raw);
        } catch (\Throwable $e) {
            Log::warning('PAYMENT_RECEIPT_EXTRACTION_ERROR', ['error' => $e->getMessage()]);

            return self::EMPTY_RESULT;
        }
    }

    private function buildPrompt(): string
    {
        return <<<PROMPT
Eres un asistente que EXTRAE datos de un comprobante de pago (transferencia, Nequi, Daviplata, etc.). NUNCA inventes un valor que no esté visible o mencionado explícitamente.

Responde EXCLUSIVAMENTE con un JSON (sin texto adicional, sin markdown) con esta forma exacta:
{
  "amount": <número, solo el monto sin símbolo de moneda> | null,
  "date": "<fecha tal como aparece>" | null,
  "time": "<hora tal como aparece>" | null,
  "reference": "<número de referencia/transacción>" | null,
  "entity": "<banco o billetera, ej. Nequi, Daviplata>" | null,
  "payer_name": "<nombre del pagador, SOLO si aparece explícitamente>" | null,
  "uncertain": true | false
}

Reglas:
- Si un dato no es legible o no aparece, su valor debe ser null — nunca lo adivines ni lo completes con un valor típico.
- "uncertain": true si la imagen/texto está borrosa, incompleta, o tienes duda razonable sobre cualquier valor extraído.
- Nunca decidas si el pago es válido, correcto, o si el monto es el esperado — eso no es tu tarea.
PROMPT;
    }

    private function parseJson(string $raw): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/```$/', '', trim($cleaned)) ?? $cleaned;

        $decoded = json_decode(trim($cleaned), true);

        if (! is_array($decoded)) {
            return self::EMPTY_RESULT;
        }

        return [
            'amount' => is_numeric($decoded['amount'] ?? null) ? (float) $decoded['amount'] : null,
            'date' => is_string($decoded['date'] ?? null) && $decoded['date'] !== '' ? $decoded['date'] : null,
            'time' => is_string($decoded['time'] ?? null) && $decoded['time'] !== '' ? $decoded['time'] : null,
            'reference' => is_string($decoded['reference'] ?? null) && $decoded['reference'] !== '' ? $decoded['reference'] : null,
            'entity' => is_string($decoded['entity'] ?? null) && $decoded['entity'] !== '' ? $decoded['entity'] : null,
            'payer_name' => is_string($decoded['payer_name'] ?? null) && $decoded['payer_name'] !== '' ? $decoded['payer_name'] : null,
            'uncertain' => (bool) ($decoded['uncertain'] ?? true),
        ];
    }
}
