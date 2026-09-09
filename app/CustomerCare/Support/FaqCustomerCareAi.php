<?php

namespace App\CustomerCare\Support;

use App\CustomerCare\Models\Faq;
use App\Factories\AIServiceFactory;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Hito 14 — camino INDEPENDIENTE (sin Training activo): la única llamada
 * IA del turno para `App\CustomerCare\Handlers\CustomerCareHandler`. La IA
 * recibe EXCLUSIVAMENTE los candidatos recuperados por
 * `FaqMatcher::retrieveCandidates()` — nunca busca libremente fuera de ese
 * contexto. Redacta la respuesta final grounded en `answer`, o genera un
 * acuse de recibo de Customer Service si ningún candidato aplica — nunca
 * ambas cosas a la vez, nunca inventa.
 *
 * Mismo contrato JSON que el bloque de FAQ de `App\Training\Support\
 * CoachService` (camino de interrupción) — ver docs/DECISIONS.md.
 */
class FaqCustomerCareAi
{
    private const EMPTY_RESULT = [
        'faq_match_id' => null, 'faq_response_text' => null,
        'customer_service_needed' => false, 'customer_service_message' => null,
    ];

    /**
     * @param  Collection<int, Faq>  $candidates
     * @return array{faq_match_id: ?int, faq_response_text: ?string, customer_service_needed: bool, customer_service_message: ?string}
     */
    public function evaluate(string $messageBody, Collection $candidates, Tenant $tenant): array
    {
        try {
            $ai = AIServiceFactory::make($tenant);
            $raw = $ai->getResponse($messageBody, $this->buildPrompt($candidates), []);

            return $this->parseJson($raw, $candidates);
        } catch (\Throwable $e) {
            Log::warning('CUSTOMER_CARE_FAQ_AI_ERROR', ['error' => $e->getMessage()]);

            // Sin respuesta fiable de la IA -> degradación segura, nunca
            // una respuesta inventada (el llamador cae al fallback humano
            // determinista, ver CustomerCareHandler/FAQ_FALLBACK_TEXT).
            return array_merge(self::EMPTY_RESULT, ['customer_service_needed' => true]);
        }
    }

    private function buildPrompt(Collection $candidates): string
    {
        $rules = <<<RULES
REGLAS DURAS, INAMOVIBLES:
- Elige, como máximo, UNA FAQ de la lista que responda la pregunta con confianza.
- Si eliges una, redacta "faq_response_text" EXCLUSIVAMENTE con base en su "answer"
  — puedes adaptar el tono, resumir o explicar mejor, pero NUNCA agregues cifras,
  plazos, políticas, condiciones o promesas que no aparezcan literalmente ahí.
  NUNCA completes con conocimiento general sobre gimnasios/negocios que no esté en
  el "answer" elegido. En este caso deja "customer_service_needed" en false.
- Si NINGUNA FAQ de la lista responde la pregunta con confianza (o no hay ninguna
  FAQ en la lista): deja "faq_match_id"/"faq_response_text" en null, pon
  "customer_service_needed" en true, y redacta "customer_service_message" —
  EXCLUSIVAMENTE un acuse de recibo:
    - NUNCA intentes responder la pregunta, ni siquiera parcialmente.
    - NUNCA uses conocimiento externo/general para completar lo que falta.
    - NUNCA inventes plazos de respuesta ("en 24 horas", "pronto").
    - NUNCA prometas una solución o resultado.
    - NUNCA afirmes que alguien ya está atendiendo el caso.
    - Solo indica que la consulta quedó registrada y que el equipo responderá por
      este mismo medio. Ejemplo de tono: "No tengo información suficiente para
      responderte con precisión. Ya estoy consultando esta pregunta con nuestro
      equipo para darte una respuesta correcta."
RULES;

        if ($candidates->isEmpty()) {
            return <<<PROMPT
Eres el asistente de atención al cliente de un negocio por WhatsApp.

No existen FAQs candidatas para esta consulta.
No intentes responder la pregunta bajo ninguna circunstancia — no tienes ningún
contexto autorizado del que partir.
Devuelve "faq_match_id": null, "faq_response_text": null,
"customer_service_needed": true, y redacta "customer_service_message" (acuse de
recibo únicamente).

{$rules}

Responde EXCLUSIVAMENTE con un JSON (sin texto adicional, sin markdown):
{
  "faq_match_id": null,
  "faq_response_text": null,
  "customer_service_needed": true,
  "customer_service_message": "<acuse de recibo>"
}
PROMPT;
        }

        $candidatesJson = json_encode(
            $candidates->map(fn (Faq $faq) => ['id' => $faq->id, 'question' => $faq->question, 'answer' => $faq->answer])->values()->all(),
            JSON_UNESCAPED_UNICODE
        );

        return <<<PROMPT
Eres el asistente de atención al cliente de un negocio por WhatsApp.

Dispones de esta lista de FAQs activas relevantes a la pregunta actual:
{$candidatesJson}

{$rules}

Responde EXCLUSIVAMENTE con un JSON (sin texto adicional, sin markdown):
{
  "faq_match_id": <id numérico de la lista de arriba> | null,
  "faq_response_text": "<redacción grounded en el answer elegido>" | null,
  "customer_service_needed": true | false,
  "customer_service_message": "<acuse de recibo, SOLO si customer_service_needed es true>" | null
}
PROMPT;
    }

    /**
     * @param  Collection<int, Faq>  $candidates
     */
    private function parseJson(string $raw, Collection $candidates): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/```$/', '', trim($cleaned)) ?? $cleaned;

        $decoded = json_decode(trim($cleaned), true);

        if (! is_array($decoded)) {
            return array_merge(self::EMPTY_RESULT, ['customer_service_needed' => true]);
        }

        return [
            'faq_match_id' => is_int($decoded['faq_match_id'] ?? null) ? $decoded['faq_match_id'] : null,
            'faq_response_text' => is_string($decoded['faq_response_text'] ?? null) && trim($decoded['faq_response_text']) !== ''
                ? $decoded['faq_response_text']
                : null,
            'customer_service_needed' => ($decoded['customer_service_needed'] ?? false) === true,
            'customer_service_message' => is_string($decoded['customer_service_message'] ?? null) && trim($decoded['customer_service_message']) !== ''
                ? $decoded['customer_service_message']
                : null,
        ];
    }
}
