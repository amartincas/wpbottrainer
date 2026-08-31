<?php

namespace App\Training\Support;

use App\Factories\AIServiceFactory;
use App\Models\Tenant;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\TrainingGoal;
use Illuminate\Support\Facades\Log;

/**
 * The Extract/Narrate halves of onboarding (Hito 5) — kept out of
 * TrainingHandler because prompt-building and JSON validation is a real,
 * independently-testable responsibility, not orchestration.
 *
 * Extract → Decide → Narrate (docs/DECISIONS.md, D007):
 * - extractFields() is Extract: the LLM turns free text into candidate
 *   structured values. Every value is validated here before it is ever
 *   trusted — an invalid/hallucinated value is dropped (becomes null), never
 *   persisted as-is. The LLM never writes to TrainingProfile directly.
 * - Deciding which field is still missing is Decide, and it does NOT happen
 *   here — TrainingHandler asks TrainingProfile::firstMissingOnboardingField()
 *   (deterministic) and only passes the *result* to nextQuestion().
 * - nextQuestion() is Narrate: the LLM only phrases a question about a field
 *   the code already decided is missing. If the call fails, a deterministic
 *   canned question is used instead — onboarding never breaks because an AI
 *   provider is down.
 *
 * No WhatsApp conversation history is ever sent to the LLM here — only the
 * current message and the already-known TrainingProfile fields (via the
 * `training_profile` ContextFragment, passed in by the caller).
 */
class OnboardingConversationService
{
    private const FIELD_TOPICS = [
        'goal' => 'su objetivo principal (perder peso, ganar músculo, fitness general o resistencia)',
        'experience_level' => 'su nivel de experiencia entrenando (principiante, intermedio o avanzado)',
        'restrictions' => 'si tiene alguna lesión, dolor o limitación física que debamos tener en cuenta',
        'available_equipment' => 'qué equipo tiene disponible para entrenar (o si entrena sin equipo)',
        'sessions_per_week' => 'cuántos días a la semana puede entrenar',
    ];

    private const FALLBACK_QUESTIONS = [
        'goal' => '¿Cuál es tu objetivo principal: perder peso, ganar músculo, mejorar tu condición física general o resistencia?',
        'experience_level' => '¿Cuál es tu nivel de experiencia entrenando: principiante, intermedio o avanzado?',
        'restrictions' => '¿Tienes alguna lesión, dolor o limitación física que debamos tener en cuenta?',
        'available_equipment' => '¿Qué equipo tienes disponible para entrenar? Por ejemplo mancuernas, bandas, barra, o ninguno.',
        'sessions_per_week' => '¿Cuántos días a la semana puedes entrenar?',
    ];

    /**
     * @param array<string, mixed>|null $knownProfile the current
     *        `training_profile` ContextFragment data (already-answered
     *        fields), so the LLM does not ask again for what it already knows.
     * @return array{goal: ?string, experience_level: ?string, restrictions: ?array,
     *               available_equipment: ?array, sessions_per_week: ?int,
     *               safety_signal_text: ?string}
     */
    public function extractFields(string $messageBody, ?array $knownProfile, Tenant $tenant): array
    {
        $empty = [
            'goal' => null,
            'experience_level' => null,
            'restrictions' => null,
            'available_equipment' => null,
            'sessions_per_week' => null,
            'safety_signal_text' => null,
        ];

        if (trim($messageBody) === '') {
            return $empty;
        }

        try {
            $ai = AIServiceFactory::make($tenant);
            $raw = $ai->getResponse($messageBody, $this->buildExtractionPrompt($knownProfile ?? []), []);

            return array_merge($empty, $this->parseExtractionJson($raw));
        } catch (\Throwable $e) {
            Log::warning('Training onboarding extraction failed', ['error' => $e->getMessage()]);

            return $empty;
        }
    }

    public function nextQuestion(string $missingField, Tenant $tenant): string
    {
        $fallback = self::FALLBACK_QUESTIONS[$missingField] ?? self::FALLBACK_QUESTIONS['goal'];

        try {
            $ai = AIServiceFactory::make($tenant);
            $topic = self::FIELD_TOPICS[$missingField] ?? $missingField;

            $systemPrompt = 'Eres un entrenador personal cercano y natural, escribiendo por WhatsApp en español. '
                ."Redacta UNA sola pregunta breve y natural (máximo 2 frases) para pedirle al usuario: {$topic}. "
                .'No uses tono de formulario. No te presentes ni saludes de nuevo.';

            $question = trim($ai->getResponse('Genera la siguiente pregunta.', $systemPrompt, []));

            return $question !== '' ? $question : $fallback;
        } catch (\Throwable $e) {
            Log::warning('Training onboarding question narration failed', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    private function buildExtractionPrompt(array $knownProfile): string
    {
        $known = json_encode($knownProfile);

        return <<<PROMPT
Eres un asistente que EXTRAE datos estructurados de un mensaje de un usuario que está configurando su perfil de entrenamiento por WhatsApp.

Perfil ya conocido (no lo repitas ni lo cambies si ya está aquí, salvo que el usuario lo corrija explícitamente): {$known}

Del mensaje del usuario, extrae ÚNICAMENTE lo que menciona explícitamente. NUNCA inventes ni asumas un valor que no fue mencionado.

Responde EXCLUSIVAMENTE con un JSON (sin texto adicional, sin markdown, sin explicación) con esta forma exacta:
{"goal": "lose_weight"|"build_muscle"|"general_fitness"|"endurance"|null, "experience_level": "beginner"|"intermediate"|"advanced"|null, "restrictions": ["tag", ...]|[]|null, "available_equipment": ["tag", ...]|[]|null, "sessions_per_week": <entero 1-14>|null, "safety_signal_text": "<frase textual si menciona dolor, lesión, síntoma o condición médica>"|null}

Usa null en cualquier campo que el mensaje no mencione. Usa [] únicamente si el usuario dice explícitamente que no tiene restricciones o no tiene equipo.
PROMPT;
    }

    /**
     * The LLM's output is never trusted as-is: every field is validated
     * against the allowed vocabulary/shape here, and anything invalid is
     * dropped (becomes null) rather than persisted.
     */
    private function parseExtractionJson(string $raw): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/```$/', '', trim($cleaned)) ?? $cleaned;

        $decoded = json_decode(trim($cleaned), true);

        if (! is_array($decoded)) {
            return [];
        }

        return [
            'goal' => $this->validateEnumValue($decoded['goal'] ?? null, TrainingGoal::class),
            'experience_level' => $this->validateEnumValue($decoded['experience_level'] ?? null, ExperienceLevel::class),
            'restrictions' => $this->validateStringArray($decoded['restrictions'] ?? null),
            'available_equipment' => $this->validateStringArray($decoded['available_equipment'] ?? null),
            'sessions_per_week' => $this->validateSessionsPerWeek($decoded['sessions_per_week'] ?? null),
            'safety_signal_text' => is_string($decoded['safety_signal_text'] ?? null) && $decoded['safety_signal_text'] !== ''
                ? $decoded['safety_signal_text']
                : null,
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
