<?php

namespace App\Training\Support;

use App\Factories\AIServiceFactory;
use App\Models\Tenant;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\Sex;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
use Illuminate\Support\Facades\Log;

/**
 * El Extract/Narrate de onboarding (Hito 5, fusionados en una sola llamada
 * de IA en el Hito 5.1, ampliado en Hito 8.3) — fuera de TrainingHandler
 * porque construir el prompt y validar el JSON es una responsabilidad real,
 * testeable de forma independiente, no orquestación.
 *
 * Extract → Decide → Narrate (docs/DECISIONS.md, D007, D026, D033):
 * - extractAndRespond() hace Extract Y Narrate en UNA sola llamada.
 * - Decidir qué campo falta de verdad sigue sin pasar por aquí — sigue
 *   siendo TrainingProfile::firstMissingOnboardingField() (determinista).
 * - resolveQuestion() es el punto de Decide para la redacción.
 *
 * Hito 8.3: se agregan `name` (persistido en Contact.customer_name, no en
 * TrainingProfile — TrainingHandler lo aplica al modelo correcto),
 * `training_location`, `equipment_fully_equipped` (representación explícita
 * de "disponibilidad amplia/completa" sin enumerar — nunca asume que CUALQUIER
 * gimnasio tiene absolutamente todo, solo registra la declaración del
 * usuario), y los 4 datos físicos (`age`/`sex`/`weight_kg`/`height_cm`) —
 * capturados desde MVP pero NUNCA bloqueantes (ver TrainingProfile).
 */
class OnboardingConversationService
{
    private const FALLBACK_QUESTIONS = [
        'name' => '¿Cómo te gustaría que te llame?',
        'goal' => '¿Cuál es tu objetivo principal: perder peso, ganar músculo, mejorar tu condición física general o resistencia?',
        'experience_level' => '¿Cuál es tu nivel de experiencia entrenando: principiante, intermedio o avanzado?',
        'primary_focus' => '¿Hay alguna zona de tu cuerpo que quieras priorizar especialmente? Por ejemplo glúteos, piernas, espalda o abdomen — o si prefieres trabajar todo por igual, también dime.',
        'training_location' => '¿Dónde vas a entrenar: en casa, en el gimnasio, o al aire libre?',
        'restrictions' => '¿Tienes alguna lesión, dolor o limitación física que debamos tener en cuenta?',
        'available_equipment' => '¿Qué equipo tienes disponible para entrenar? Por ejemplo mancuernas, bandas, barra, o ninguno.',
        'sessions_per_week' => '¿Cuántos días a la semana puedes entrenar?',
        'physical_stats' => 'Para terminar de afinar tu plan, si quieres cuéntame tu edad, sexo, peso y estatura — no es obligatorio.',
    ];

    /**
     * Hito 5.1/8.3/8.4: mapea cada campo pendiente al valor de `next_action`
     * que la IA debe usar cuando ESE es el campo que falta. Código puro,
     * cerrado — nunca lo decide la IA. Único consumidor: resolveQuestion().
     * "complete_onboarding" no aparece aquí a propósito.
     */
    private const NEXT_ACTION_MAP = [
        'name' => 'ask_name',
        'goal' => 'ask_goal',
        'experience_level' => 'ask_experience_level',
        'primary_focus' => 'ask_primary_focus',
        'training_location' => 'ask_training_location',
        'restrictions' => 'ask_restrictions',
        'available_equipment' => 'ask_equipment',
        'sessions_per_week' => 'ask_sessions_per_week',
        'physical_stats' => 'ask_physical_stats',
    ];

    private const VALID_NEXT_ACTIONS = [
        'ask_name', 'ask_goal', 'ask_experience_level', 'ask_primary_focus', 'ask_training_location',
        'ask_restrictions', 'ask_equipment', 'ask_sessions_per_week', 'ask_physical_stats',
        'complete_onboarding',
    ];

    /**
     * Un `response` más largo que esto se descarta igual que uno vacío —
     * probablemente una alucinación/repetición, no una pregunta natural
     * breve de WhatsApp.
     */
    private const MAX_RESPONSE_LENGTH = 300;

    /**
     * Extract + Narrate en una sola llamada.
     *
     * @param array<string, mixed>|null $knownProfile el ContextFragment
     *        `training_profile` actual (campos ya respondidos, incluido el
     *        nombre desde Contact), para que la IA no vuelva a preguntar lo
     *        que ya sabe.
     * @return array{
     *     extracted: array{
     *         name: ?string, goal: ?string, experience_level: ?string,
     *         primary_focus: ?array, secondary_focus: ?array,
     *         training_location: ?string, available_equipment: ?array,
     *         equipment_fully_equipped: ?bool, restrictions: ?array,
     *         sessions_per_week: ?int, age: ?int, sex: ?string,
     *         weight_kg: ?float, height_cm: ?int, safety_signal_text: ?string,
     *     },
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
     * (la única autoridad) — nunca de la IA.
     */
    public function resolveQuestion(string $realMissingField, ?string $aiNextAction, ?string $aiResponse): string
    {
        $expectedAction = self::NEXT_ACTION_MAP[$realMissingField] ?? null;

        if ($expectedAction !== null && $aiNextAction === $expectedAction && $this->isUsableResponse($aiResponse)) {
            return trim($aiResponse);
        }

        return self::FALLBACK_QUESTIONS[$realMissingField] ?? self::FALLBACK_QUESTIONS['goal'];
    }

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
                'name' => null,
                'goal' => null,
                'experience_level' => null,
                'primary_focus' => null,
                'secondary_focus' => null,
                'training_location' => null,
                'available_equipment' => null,
                'equipment_fully_equipped' => null,
                'restrictions' => null,
                'sessions_per_week' => null,
                'age' => null,
                'sex' => null,
                'weight_kg' => null,
                'height_cm' => null,
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
  "extracted": {
    "name": "<nombre o como quiere que le llamen>" | null,
    "goal": "lose_weight"|"build_muscle"|"general_fitness"|"endurance"|null,
    "experience_level": "beginner"|"intermediate"|"advanced"|null,
    "primary_focus": ["glutes"|"quads"|"hamstrings"|"calves"|"chest"|"back"|"shoulders"|"biceps"|"triceps"|"abs"|"full_body", ...]|[]|null,
    "secondary_focus": ["glutes"|"quads"|"hamstrings"|"calves"|"chest"|"back"|"shoulders"|"biceps"|"triceps"|"abs"|"full_body", ...]|[]|null,
    "training_location": "home"|"gym"|"outdoor"|null,
    "available_equipment": ["tag", ...]|[]|null,
    "equipment_fully_equipped": true|false|null,
    "restrictions": ["tag", ...]|[]|null,
    "sessions_per_week": <entero 1-14>|null,
    "age": <entero>|null,
    "sex": "male"|"female"|"prefer_not_to_say"|null,
    "weight_kg": <número>|null,
    "height_cm": <entero>|null,
    "safety_signal_text": "<frase textual>"|null
  },
  "next_action": "ask_name"|"ask_goal"|"ask_experience_level"|"ask_primary_focus"|"ask_training_location"|"ask_restrictions"|"ask_equipment"|"ask_sessions_per_week"|"ask_physical_stats"|"complete_onboarding",
  "response": "<tu respuesta conversacional en español>"
}

Reglas de "extracted":
- Usa null en cualquier campo que el mensaje no mencione. Usa [] únicamente si el usuario dice explícitamente que no tiene restricciones, no tiene equipo, o no tiene ninguna zona que priorizar (quiere trabajar todo por igual).
- Cualquier lesión, dolor, molestia o limitación física que el usuario mencione (ej. "dolor en la rodilla", "molestia en la espalda") va SIEMPRE en "restrictions", sin importar si también aparece en "safety_signal_text" — son campos independientes, pueden llenarse ambos a la vez o solo uno.
- "safety_signal_text": SOLO llénalo si el mensaje sugiere una posible urgencia médica real (dolor de pecho, dificultad para respirar, pérdida de conocimiento/desmayo, cirugía muy reciente, entumecimiento/hormigueo severo, lesión grave repentina, o complicación de embarazo). Una molestia o dolor ordinario de entrenamiento (rodilla, espalda, hombro, ciática, etc., sin esos signos) NO es una urgencia — usa null aquí aunque sí llenes "restrictions". Ejemplo: "tengo dolor en las rodillas" → restrictions: ["dolor en las rodillas"], safety_signal_text: null.
- "equipment_fully_equipped": true SOLO si el usuario indica acceso amplio o completo a equipo SIN enumerar (ej. "tengo de todo", "tengo todo", "lo normal de un gimnasio", "está bien equipado") — en ese caso "available_equipment" puede quedar null o vacío, NUNCA inventes una lista de aparatos. Si el usuario menciona equipo específico (ej. "solo pesas", "tengo mancuernas y bandas", o incluso solo dice "gimnasio" sin más detalle sobre qué tiene), usa "available_equipment" con lo mencionado (o null si solo dijo el lugar, sin hablar de equipo) y deja "equipment_fully_equipped" en null — decir dónde entrena no es lo mismo que declarar que tiene todo el equipo.
- "primary_focus": la(s) zona(s) que el usuario indica querer PRIORIZAR especialmente (no es lo mismo que un simple "quiero ponerme en forma", eso es "goal"). Traduce el lenguaje natural a este vocabulario cerrado, usando esta tabla:
  - "glúteos"/"cola"/"pompis" → ["glutes"]
  - "piernas" (sin especificar más) → ["quads", "hamstrings", "glutes", "calves"]
  - "cuádriceps"/"muslos" → ["quads"]
  - "isquiotibiales"/"femorales" → ["hamstrings"]
  - "pantorrillas"/"gemelos" → ["calves"]
  - "espalda" → ["back"]
  - "abdomen"/"abdominales"/"core"/"panza" → ["abs"]
  - "pecho"/"pectorales" → ["chest"]
  - "brazos" (sin especificar más) → ["biceps", "triceps"]
  - "bíceps" → ["biceps"]
  - "tríceps" → ["triceps"]
  - "hombros" → ["shoulders"]
  - "todo por igual"/"todo el cuerpo"/"no tengo preferencia"/"parejo" → [] (respuesta válida, NUNCA null en este caso)
  Si el usuario menciona una zona con MENOR énfasis o de forma secundaria junto a la principal (ej. "sobre todo glúteos, y algo de espalda también"), la principal va en "primary_focus" y la secundaria en "secondary_focus" — NUNCA reveles ni menciones estos dos términos técnicos al usuario, son solo para uso interno.
- Puedes extraer VARIOS campos de un mismo mensaje si el usuario los menciona juntos (ej. "Me llamo Ana, entreno en un gimnasio y tengo de todo" → name, training_location y equipment_fully_equipped los tres a la vez).
- Los datos físicos (age/sex/weight_kg/height_cm) son opcionales para el usuario — si responde "prefiero no decir" o cambia de tema, dejas esos campos en null, nunca insistas ni los inventes.

Reglas de "next_action": indica cuál de estos campos pendientes sigue sin responderse, en este orden de prioridad: name, goal, experience_level, primary_focus, training_location, available_equipment, restrictions, sessions_per_week, y por último (opcional) datos físicos. Usa "complete_onboarding" solo si ya no falta nada de lo anterior. Este valor es solo orientativo — el sistema siempre verifica el estado real antes de usarlo.

Reglas de "response": redacta en tono natural y cercano, como un entrenador personal real — NUNCA como un formulario. Si ya conoces el nombre del usuario, puedes usarlo con naturalidad. Si el onboarding sigue incompleto, reconoce brevemente lo que el usuario acaba de decir y luego haz la siguiente pregunta de forma conversacional. Máximo 2-3 frases. Nunca uses los términos técnicos "primary_focus"/"secondary_focus" — habla de "zona a priorizar" o similar, en lenguaje natural.
PROMPT;
    }

    /**
     * El resultado de la IA nunca se confía tal cual: cada campo de
     * "extracted" se valida contra el vocabulario/forma permitida aquí, y
     * cualquier valor inválido se descarta (queda null) en vez de
     * persistirse.
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
                'name' => $this->validateName($extractedRaw['name'] ?? null),
                'goal' => $this->validateEnumValue($extractedRaw['goal'] ?? null, TrainingGoal::class),
                'experience_level' => $this->validateEnumValue($extractedRaw['experience_level'] ?? null, ExperienceLevel::class),
                'primary_focus' => $this->validateMuscleFocusArray($extractedRaw['primary_focus'] ?? null),
                'secondary_focus' => $this->validateMuscleFocusArray($extractedRaw['secondary_focus'] ?? null),
                'training_location' => $this->validateEnumValue($extractedRaw['training_location'] ?? null, TrainingLocation::class),
                'available_equipment' => $this->validateStringArray($extractedRaw['available_equipment'] ?? null),
                'equipment_fully_equipped' => $this->validateBool($extractedRaw['equipment_fully_equipped'] ?? null),
                'restrictions' => $this->validateStringArray($extractedRaw['restrictions'] ?? null),
                'sessions_per_week' => $this->validateSessionsPerWeek($extractedRaw['sessions_per_week'] ?? null),
                'age' => $this->validateIntRange($extractedRaw['age'] ?? null, 10, 100),
                'sex' => $this->validateEnumValue($extractedRaw['sex'] ?? null, Sex::class),
                'weight_kg' => $this->validateNumericRange($extractedRaw['weight_kg'] ?? null, 20, 300),
                'height_cm' => $this->validateIntRange($extractedRaw['height_cm'] ?? null, 100, 250),
                'safety_signal_text' => is_string($extractedRaw['safety_signal_text'] ?? null) && $extractedRaw['safety_signal_text'] !== ''
                    ? $extractedRaw['safety_signal_text']
                    : null,
            ],
            'next_action' => is_string($nextAction) && in_array($nextAction, self::VALID_NEXT_ACTIONS, true) ? $nextAction : null,
            'response' => is_string($decoded['response'] ?? null) ? $decoded['response'] : null,
        ];
    }

    private function validateName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return ($trimmed !== '' && mb_strlen($trimmed) <= 60) ? $trimmed : null;
    }

    private function validateBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
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

    /**
     * A diferencia de validateStringArray(), restringe cada elemento al
     * vocabulario cerrado de MuscleFocus — cualquier valor que la IA
     * hubiera inventado fuera de esta lista se descarta silenciosamente
     * (nunca se persiste una zona muscular que el código no reconoce).
     */
    private function validateMuscleFocusArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($v) => is_string($v) ? MuscleFocus::tryFrom($v)?->value : null, $value)
        )));
    }

    private function validateSessionsPerWeek(mixed $value): ?int
    {
        return $this->validateIntRange($value, 1, 14);
    }

    private function validateIntRange(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;
        } else {
            return null;
        }

        return ($int >= $min && $int <= $max) ? $int : null;
    }

    private function validateNumericRange(mixed $value, float $min, float $max): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return ($float >= $min && $float <= $max) ? $float : null;
    }
}
