<?php

namespace App\Training\Onboarding;

/**
 * Bloque 4 — DTO readonly (mismo patrón que App\Core\Messaging\IngestedMessage,
 * Hito 2), transporta lo que un `OnboardingRequirement` sabe sobre SU propia
 * pregunta pendiente, sin decidir el texto final que verá el usuario.
 *
 * NUNCA contiene el texto final de WhatsApp — la redacción natural es
 * responsabilidad exclusiva de la IA, dentro de la única llamada combinada
 * de `OnboardingConversationService`. La única excepción deliberada es
 * `fallbackQuestion`: una red de seguridad 100% determinista, usada
 * SOLAMENTE cuando la salida de la IA es inválida/inutilizable — nunca la
 * experiencia normal (ver `OnboardingConversationService::resolveQuestion()`).
 */
final readonly class QuestionContext
{
    /**
     * @param  array<int, string>|null  $validOptions  Vocabulario cerrado si
     *         aplica (ej. los valores de TrainingGoal), o null si es texto
     *         libre / no aplica una lista cerrada.
     * @param  array<string, mixed>  $knownContext  Slice de lo ya confirmado
     *         del perfil, relevante para ESTA pregunta — nunca inventado,
     *         siempre un subconjunto de datos reales ya persistidos.
     */
    public function __construct(
        public string $key,
        public string $purpose,
        public bool $blocking,
        public string $expectedType,
        public ?array $validOptions,
        public array $knownContext,
        public string $fallbackQuestion,
    ) {}
}
