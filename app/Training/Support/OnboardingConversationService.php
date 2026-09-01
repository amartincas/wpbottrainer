<?php

namespace App\Training\Support;

use App\Factories\AIServiceFactory;
use App\Models\Tenant;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\TrainingGoal;
use Illuminate\Support\Facades\Log;

/**
 * El Extract/Narrate de onboarding (Hito 5, fusionados en una sola llamada
 * de IA en el Hito 5.1) — fuera de TrainingHandler porque construir el
 * prompt y validar el JSON es una responsabilidad real, testeable de forma
 * independiente, no orquestación.
 *
 * Extract → Decide → Narrate (docs/DECISIONS.md, D007 y D026):
 * - extractAndRespond() hace Extract Y Narrate en UNA sola llamada: el LLM
 *   devuelve `extracted` (candidatos estructurados, nunca confiados
 *   ciegamente — cada valor se valida aquí antes de existir para el
 *   llamador) + `next_action` (una SEÑAL, no una decisión) + `response`
 *   (una redacción natural candidata).
 * - Decidir qué campo falta de verdad sigue sin pasar por aquí — sigue
 *   siendo TrainingProfile::firstMissingOnboardingField() (determinista),
 *   invocado por TrainingHandler después de aplicar los campos extraídos.
 * - resolveQuestion() es el punto de Decide para la redacción: compara el
 *   `next_action` de la IA contra el campo real que el código ya determinó
 *   que falta. Solo si coinciden exactamente, Y `response` es utilizable,
 *   se usa el texto de la IA — en cualquier otro caso (incluida una IA que
 *   diga "complete_onboarding" cuando en realidad falta algo) se usa
 *   FALLBACK_QUESTIONS. La IA nunca tiene autoridad sobre esta decisión,
 *   solo sobre la redacción cuando el código ya confirmó que aplica.
 *
 * No se envía historial de conversación de WhatsApp a la IA aquí — solo el
 * mensaje actual y los campos ya conocidos del TrainingProfile (vía el
 * ContextFragment `training_profile`, pasado por el llamador).
 */
class OnboardingConversationService
{
    private const FALLBACK_QUESTIONS = [
        'goal' => '¿Cuál es tu objetivo principal: perder peso, ganar músculo, mejorar tu condición física general o resistencia?',
        'experience_level' => '¿Cuál es tu nivel de experiencia entrenando: principiante, intermedio o avanzado?',
        'restrictions' => '¿Tienes alguna lesión, dolor o limitación física que debamos tener en cuenta?',
        'available_equipment' => '¿Qué equipo tienes disponible para entrenar? Por ejemplo mancuernas, bandas, barra, o ninguno.',
        'sessions_per_week' => '¿Cuántos días a la semana puedes entrenar?',
    ];

    /**
     * Hito 5.1: mapea cada campo de TrainingProfile al valor de
     * `next_action` que la IA debe usar cuando ESE es el campo que falta.
     * Código puro, cerrado — nunca lo decide la IA. Único consumidor:
     * resolveQuestion(). "complete_onboarding" no aparece aquí a propósito:
     * no hay ningún campo real que mapee a él, así que nunca puede
     * "coincidir" con un campo pendiente — es estructuralmente imposible
     * que ese valor por sí solo complete el onboarding.
     */
    private const NEXT_ACTION_MAP = [
        'goal' => 'ask_goal',
        'experience_level' => 'ask_experience_level',
        'restrictions' => 'ask_restrictions',
        'available_equipment' => 'ask_equipment',
        'sessions_per_week' => 'ask_sessions_per_week',
    ];

    private const VALID_NEXT_ACTIONS = [
        'ask_goal', 'ask_experience_level', 'ask_restrictions', 'ask_equipment', 'ask_sessions_per_week',
        'complete_onboarding',
    ];

    /**
     * Un `response` más largo que esto se descarta igual que uno vacío —
     * probablemente una alucinación/repetición, no una pregunta natural
     * breve de WhatsApp.
     */
    private const MAX_RESPONSE_LENGTH = 300;

    /**
     * Extract + Narrate en una sola llamada (Hito 5.1 — antes eran 2
     * llamadas secuenciales: extractFields() + nextQuestion()).
     *
     * @param array<string, mixed>|null $knownProfile el ContextFragment
     *        `training_profile` actual (campos ya respondidos), para que la
     *        IA no vuelva a preguntar lo que ya sabe.
     * @return array{
     *     extracted: array{goal: ?string, experience_level: ?string, restrictions: ?array,
     *                        available_equipment: ?array, sessions_per_week: ?int, safety_signal_text: ?string},
     *     next_action: ?string,
     *     response: ?string,
     * }
     */
    public function extractAndRespond(string $messageBody, ?array $knownProfile, Tenant $tenant): array
    {
        $empty = $this->emptyResult();

        if (trim($messageBody) === '') {
            return $empty;
        }

        try {
            $ai = AIServiceFactory::make($tenant);
            $raw = $ai->getResponse($messageBody, $this->buildCombinedPrompt($knownProfile ?? []), []);

            return $this->parseCombinedJson($raw);
        } catch (\Throwable $e) {
            Log::warning('Training onboarding extraction failed', ['error' => $e->getMessage()]);

            return $empty;
        }
    }

    /**
     * Decide, de forma 100% determinista, qué pregunta enviar al usuario.
     * $realMissingField viene de TrainingProfile::firstMissingOnboardingField()
     * (la única autoridad) — nunca de la IA. La respuesta de la IA
     * ($aiNextAction/$aiResponse, del mismo turno que ya se procesó) solo se
     * usa si coincide exactamente con lo que el código ya sabe que falta.
     */
    public function resolveQuestion(string $realMissingField, ?string $aiNextAction, ?string $aiResponse): string
    {
        $expectedAction = self::NEXT_ACTION_MAP[$realMissingField] ?? null;

        if ($expectedAction !== null && $aiNextAction === $expectedAction && $this->isUsableResponse($aiResponse)) {
            return trim($aiResponse);
        }

        return self::FALLBACK_QUESTIONS[$realMissingField] ?? self::FALLBACK_QUESTIONS['goal'];
    }

    /**
     * Hito 5.1: true solo cuando se usó la redacción de la IA — útil para
     * medir, en observabilidad, qué proporción de turnos reales terminó en
     * fallback vs. respuesta natural.
     */
    public function usedAiResponse(string $realMissingField, ?string $aiNextAction, ?string $aiResponse): bool
    {
        $expectedAction = self::NEXT_ACTION_MAP[$realMissingField] ?? null;

        return $expectedAction !== null && $aiNextAction === $expectedAction && $this->isUsableResponse($aiResponse);
    }

    private function isUsableResponse(?string $response): bool
    {
        if ($response === null) {
            return false;
        }

        $trimmed = trim($response);

        return $trimmed !== '' && mb_strlen($trimmed) <= self::MAX_RESPONSE_LENGTH;
    }

    private function emptyResult(): array
    {
        return [
            'extracted' => [
                'goal' => null,
                'experience_level' => null,
                'restrictions' => null,
                'available_equipment' => null,
                'sessions_per_week' => null,
                'safety_signal_text' => null,
            ],
            'next_action' => null,
            'response' => null,
        ];
    }

    private function buildCombinedPrompt(array $knownProfile): string
    {
        $known = json_encode($knownProfile);

        return <<<PROMPT
Eres un entrenador personal cercano, escribiendo por WhatsApp en español, ayudando a un usuario a configurar su perfil de entrenamiento.

Perfil ya conocido (no lo repitas ni lo cambies si ya está aquí, salvo que el usuario lo corrija explícitamente): {$known}

Del mensaje del usuario, extrae ÚNICAMENTE lo que menciona explícitamente. NUNCA inventes ni asumas un valor que no fue mencionado.

Responde EXCLUSIVAMENTE con un JSON (sin texto adicional, sin markdown, sin explicación) con esta forma exacta:
{
  "extracted": {"goal": "lose_weight"|"build_muscle"|"general_fitness"|"endurance"|null, "experience_level": "beginner"|"intermediate"|"advanced"|null, "restrictions": ["tag", ...]|[]|null, "available_equipment": ["tag", ...]|[]|null, "sessions_per_week": <entero 1-14>|null, "safety_signal_text": "<frase textual>"|null},
  "next_action": "ask_goal"|"ask_experience_level"|"ask_restrictions"|"ask_equipment"|"ask_sessions_per_week"|"complete_onboarding",
  "response": "<tu respuesta conversacional en español>"
}

Reglas de "extracted":
- Usa null en cualquier campo que el mensaje no mencione. Usa [] únicamente si el usuario dice explícitamente que no tiene restricciones o no tiene equipo.
- Cualquier lesión, dolor, molestia o limitación física que el usuario mencione (ej. "dolor en la rodilla", "molestia en la espalda") va SIEMPRE en "restrictions", sin importar si también aparece en "safety_signal_text" — son campos independientes, pueden llenarse ambos a la vez o solo uno.
- "safety_signal_text": SOLO llénalo si el mensaje sugiere una posible urgencia médica real (dolor de pecho, dificultad para respirar, pérdida de conocimiento/desmayo, cirugía muy reciente, entumecimiento/hormigueo severo, lesión grave repentina, o complicación de embarazo). Una molestia o dolor ordinario de entrenamiento (rodilla, espalda, hombro, ciática, etc., sin esos signos) NO es una urgencia — usa null aquí aunque sí llenes "restrictions". Ejemplo: "tengo dolor en las rodillas" → restrictions: ["dolor en las rodillas"], safety_signal_text: null.

Reglas de "next_action": indica cuál de estos 5 campos obligatorios sigue sin responderse (goal, experience_level, restrictions, available_equipment, sessions_per_week), considerando el perfil ya conocido MÁS lo que acabas de extraer de este mensaje. Usa "complete_onboarding" solo si los 5 ya están respondidos. Este valor es solo orientativo — el sistema siempre verifica el estado real antes de usarlo.

Reglas de "response": redacta en tono natural y cercano, como un entrenador personal real — NUNCA como un formulario. Si el onboarding sigue incompleto, reconoce brevemente lo que el usuario acaba de decir y luego haz la siguiente pregunta de forma conversacional. Máximo 2-3 frases.
PROMPT;
    }

    /**
     * El resultado de la IA nunca se confía tal cual: cada campo de
     * "extracted" se valida contra el vocabulario/forma permitida aquí, y
     * cualquier valor inválido se descarta (queda null) en vez de
     * persistirse. "next_action" se descarta si no es una de las 6 claves
     * cerradas conocidas. "response" se deja tal cual si es string —
     * isUsableResponse()/resolveQuestion() deciden si se usa.
     */
    private function parseCombinedJson(string $raw): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/```$/', '', trim($cleaned)) ?? $cleaned;

        $decoded = json_decode(trim($cleaned), true);

        if (! is_array($decoded)) {
            return $this->emptyResult();
        }

        $extractedRaw = is_array($decoded['extracted'] ?? null) ? $decoded['extracted'] : [];
        $nextAction = $decoded['next_action'] ?? null;

        return [
            'extracted' => [
                'goal' => $this->validateEnumValue($extractedRaw['goal'] ?? null, TrainingGoal::class),
                'experience_level' => $this->validateEnumValue($extractedRaw['experience_level'] ?? null, ExperienceLevel::class),
                'restrictions' => $this->validateStringArray($extractedRaw['restrictions'] ?? null),
                'available_equipment' => $this->validateStringArray($extractedRaw['available_equipment'] ?? null),
                'sessions_per_week' => $this->validateSessionsPerWeek($extractedRaw['sessions_per_week'] ?? null),
                'safety_signal_text' => is_string($extractedRaw['safety_signal_text'] ?? null) && $extractedRaw['safety_signal_text'] !== ''
                    ? $extractedRaw['safety_signal_text']
                    : null,
            ],
            'next_action' => is_string($nextAction) && in_array($nextAction, self::VALID_NEXT_ACTIONS, true) ? $nextAction : null,
            'response' => is_string($decoded['response'] ?? null) ? $decoded['response'] : null,
        ];
    }

    private function validateEnumValue(mixed $value, string $enumClass): ?string
    {
        return is_string($value) ? $enumClass::tryFrom($value)?->value : null;
    }

    private function validateStringArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, fn ($v) => is_string($v) && $v !== ''));
    }

    private function validateSessionsPerWeek(mixed $value): ?int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;
        } else {
            return null;
        }

        return ($int >= 1 && $int <= 14) ? $int : null;
    }
}
