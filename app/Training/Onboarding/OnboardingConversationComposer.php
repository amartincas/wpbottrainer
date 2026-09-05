<?php

namespace App\Training\Onboarding;

/**
 * Bloque 4 — Opción A (aprobada): NO llama a ningún proveedor de IA. Es una
 * pieza puramente determinista que traduce un `QuestionContext` en un
 * fragmento de texto para inyectar en el ÚNICO prompt combinado que
 * `OnboardingConversationService` ya construye — preserva exactamente 1
 * llamada de IA por turno (D026), en vez de una segunda llamada dedicada a
 * "componer la pregunta".
 *
 * Responsabilidad única y acotada: no decide QUÉ requirement preguntar (lo
 * recibe ya decidido de `OnboardingRequirementRegistry`), no persiste nada,
 * no toca `TrainingProfile`/`TrainingAccess`, no decide seguridad. El texto
 * final visible por WhatsApp lo sigue redactando la IA dentro de esa única
 * llamada — este fragmento es solo una instrucción adicional para ella.
 */
class OnboardingConversationComposer
{
    /**
     * Construye la invitación NO bloqueante a un requirement oportunista
     * (ej. primary_focus) para agregar al prompt combinado, según la
     * política de turnos progresivos (ver
     * OnboardingRequirementRegistry::secondaryOpportunisticFor()).
     *
     * Deliberadamente genérico y breve — nunca repite el fallbackQuestion
     * de $context (eso pertenece únicamente a la red de seguridad
     * determinista de OnboardingConversationService::resolveQuestion()).
     */
    public function describeOpportunisticInvitation(QuestionContext $context): string
    {
        return "\nSi además, dentro de tu respuesta principal, resulta natural, invita brevemente (sin insistir, sin tratarlo como obligatorio ni como una segunda pregunta formal) a que el usuario comparta: {$context->purpose}. Si no lo menciona, continúa con normalidad — nunca es un requisito para empezar a entrenar.\n";
    }
}
