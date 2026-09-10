<?php

namespace App\Training\Support;

use App\Factories\AIServiceFactory;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * H16.1 (Cambio 4) — mismo contrato exacto que `ReminderMessageComposer`
 * (Hito 10): la aplicación ya decidió TODO lo de negocio antes de llegar
 * aquí (`TrainingAccessGate` ya denegó; `TrainingHandler` ya determinó el
 * `access_state` real leyendo `TrainingAccess`/`WorkoutSession`) — esta
 * clase ÚNICAMENTE redacta CÓMO se dice. Nunca se invoca para un Contact que
 * nunca tuvo acceso (ese caso sigue usando `TrainingHandler::ACCESS_REQUIRED_MESSAGE`,
 * sin cambios) — solo para Trial vencido (con al menos una sesión
 * completada), Active/Free vencido, o Revoked.
 *
 * La IA no puede decidir que el acceso terminó, cuántas sesiones hubo, ni
 * si el usuario debe pagar — solo redacta el texto final a partir de
 * hechos ya resueltos. Cualquier fallo de la IA (excepción, timeout,
 * formato inválido, texto vacío o demasiado largo) usa INMEDIATAMENTE el
 * fallback determinista — la indisponibilidad de la IA nunca impide que el
 * usuario reciba una respuesta.
 *
 * Máximo UNA llamada de IA por invocación — este flujo (denegación de
 * acceso en el paso 3, o `TrainingAccessDeniedException` en el paso 6)
 * nunca comparte turno con `OnboardingConversationService`/`CoachService`/
 * `ExecutionReportService` (ver docs/DECISIONS.md, hallazgo de la revisión
 * arquitectónica H16.1: `TrainingAccessGate` deniega ANTES de que cualquier
 * otro camino del turno pueda hacer una llamada de IA).
 */
class TrialEndedMessageComposer
{
    private const MAX_LENGTH = 400;

    /**
     * @param  array{access_state: string, completed_sessions_count: ?int}  $facts
     *         `access_state` es uno de: "trial_expired", "paid_expired", "revoked".
     *         `completed_sessions_count` solo tiene sentido para "trial_expired"
     *         (siempre > 0 — el caso de 0 sesiones nunca llega a esta clase,
     *         ver TrainingHandler::respondToDenial()).
     */
    public function compose(array $facts, Tenant $tenant): string
    {
        try {
            $ai = AIServiceFactory::make($tenant);
            $raw = $ai->getResponse('Redacta el mensaje ahora.', $this->buildPrompt($facts), []);

            return $this->validate($raw) ?? $this->fallbackFor($facts);
        } catch (\Throwable $e) {
            Log::warning('TRIAL_ENDED_MESSAGE_COMPOSE_FAILED', [
                'tenant_id' => $tenant->id,
                'access_state' => $facts['access_state'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackFor($facts);
        }
    }

    private function buildPrompt(array $facts): string
    {
        $stateInstructions = match ($facts['access_state']) {
            'trial_expired' => "El período de prueba gratuita del usuario terminó. Completó {$facts['completed_sessions_count']} sesión(es) de entrenamiento durante ese período. Puede continuar escribiendo \"quiero pagar\".",
            'paid_expired' => 'La membresía pagada del usuario venció. Su historial de entrenamiento permanece intacto. Puede continuar escribiendo "quiero pagar".',
            'revoked' => 'El acceso del usuario está pausado administrativamente. NO sabes la causa real. NO es un Trial vencido ni una membresía vencida — no lo menciones. Debe escribir para que el equipo lo revise, nunca invitarlo a pagar directamente.',
            default => 'El acceso del usuario no está disponible actualmente.',
        };

        return <<<PROMPT
Eres el asistente de WpbotTrainer en WhatsApp. Tu ÚNICA tarea es redactar UN mensaje breve, honesto y sin urgencia artificial, usando EXCLUSIVAMENTE este hecho ya determinado por el sistema:

{$stateInstructions}

REGLAS DURAS, INAMOVIBLES:
- NUNCA inventes un precio, descuento ni promoción.
- NUNCA inventes una urgencia ("se acaba", "última oportunidad") que no esté en el hecho de arriba.
- NUNCA prometas nada que no esté explícito arriba.
- Si el hecho es de acceso pausado ("revoked"), NUNCA menciones "Trial" ni "membresía vencida", y NUNCA invites a escribir "quiero pagar" — solo indica que puede escribir para que el equipo lo revise.
- Responde EXCLUSIVAMENTE con el texto final del mensaje — sin JSON, sin comillas envolventes, sin explicaciones, sin markdown.
PROMPT;
    }

    /**
     * Fallback determinista aprobado por caso — el mismo copy ya cerrado en
     * el diseño de H16.1, usado únicamente cuando la IA falla o produce una
     * salida inválida.
     */
    private function fallbackFor(array $facts): string
    {
        return match ($facts['access_state']) {
            'trial_expired' => sprintf(
                'Tu período de prueba terminó — en estos días hiciste %d sesiones conmigo. Si quieres seguir, escríbeme "quiero pagar" y continuamos exactamente donde vamos.',
                $facts['completed_sessions_count'] ?? 0,
            ),
            'paid_expired' => 'Tu acceso venció, pero nada de tu progreso se perdió. Dime "quiero pagar" y continuamos con tu plan.',
            'revoked' => 'Tu acceso está pausado en este momento. Escríbeme y te pongo en contacto con nuestro equipo para revisarlo.',
            default => 'Tu perfil ya está listo. 💪 Para comenzar a entrenar necesitas activar tu acceso. Escribe "quiero pagar" para ver las opciones.',
        };
    }

    /**
     * Cualquier señal de que la respuesta no cumple el contrato (vacía,
     * parece JSON/markdown, demasiado larga) degrada al fallback — nunca se
     * "arregla" ni se trunca el texto de la IA. Mismo criterio exacto que
     * `ReminderMessageComposer::validate()`.
     */
    private function validate(string $raw): ?string
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:\w+)?/', '', $text) ?? $text;
        $text = trim(preg_replace('/```$/', '', $text) ?? $text);

        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return null;
        }

        if (str_starts_with($text, '{') || str_starts_with($text, '[')) {
            return null;
        }

        return $text;
    }
}
