<?php

namespace App\Training\Support;

use App\Factories\AIServiceFactory;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Hito 10 — la IA redacta CÓMO se dice el recordatorio; el código ya
 * decidió TODO lo demás (cuándo, qué significa, la acción esperada) antes
 * de llegar aquí. Contrato: recibe ÚNICAMENTE hechos estructurados y
 * confiables (`reminder_type`, `local_date`, `local_time`,
 * `recurrence_description`, `training_context`, `expected_action`), nunca
 * `CoachContext` ni historial de conversación — este NO es un turno
 * conversacional, es un evento proactivo (D052/D053).
 *
 * La IA no puede cambiar fecha/hora/recurrencia, inventar disponibilidad,
 * una sesión, ejercicios, progresión ni información comercial, ni crear o
 * modificar el Reminder — solo devuelve el texto final a enviar. Cualquier
 * fallo de la IA (excepción, timeout, formato inválido, texto vacío, texto
 * que no cumple el contrato) usa INMEDIATAMENTE el fallback determinista —
 * la indisponibilidad de la IA nunca impide el envío del recordatorio.
 *
 * Máximo UNA llamada de IA por ejecución de Reminder — esto no es un turno
 * iniciado por el usuario, así que no compite con la regla de "una sola
 * llamada por turno conversacional" de Bloque 9; es, en sí misma, la única
 * llamada de esta ejecución proactiva.
 */
class ReminderMessageComposer
{
    private const MAX_LENGTH = 400;

    /**
     * Fallback determinista aprobado — usa ÚNICAMENTE hechos ya resueltos
     * por código (ninguno en este texto fijo, deliberadamente genérico:
     * cualquier variable haría que el fallback dependiera, a su vez, de que
     * el formateo no falle).
     */
    public const FALLBACK_TEXT = '💪 Hoy toca entrenar. Cuando estés listo, dime "sí" y preparo tu rutina.';

    /**
     * @param  array{reminder_type: string, local_date: string, local_time: string, recurrence_description: ?string, training_context: string, expected_action: string}  $facts
     */
    public function compose(array $facts, Tenant $tenant): string
    {
        try {
            $ai = AIServiceFactory::make($tenant);
            $raw = $ai->getResponse('Redacta el mensaje ahora.', $this->buildPrompt($facts), []);

            return $this->validate($raw) ?? self::FALLBACK_TEXT;
        } catch (\Throwable $e) {
            Log::warning('REMINDER_MESSAGE_COMPOSE_FAILED', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return self::FALLBACK_TEXT;
        }
    }

    private function buildPrompt(array $facts): string
    {
        $recurrence = $facts['recurrence_description'] ?? 'ocurrencia única, no se repite';

        return <<<PROMPT
Eres el asistente de WpbotTrainer en WhatsApp. Tu ÚNICA tarea es redactar UN mensaje corto, natural y motivador que invite al usuario a entrenar, usando EXCLUSIVAMENTE estos hechos ya decididos por el sistema:

- tipo de recordatorio: {$facts['reminder_type']}
- fecha: {$facts['local_date']}
- hora: {$facts['local_time']}
- recurrencia: {$recurrence}
- contexto: {$facts['training_context']}
- acción esperada del usuario: {$facts['expected_action']} (debe responder algo como "sí" para que el sistema continúe)

REGLAS DURAS, INAMOVIBLES:
- NUNCA cambies ni inventes una fecha/hora distinta a la indicada arriba.
- NUNCA inventes disponibilidad, una sesión concreta, ejercicios ni una progresión — no tienes esa información y este mensaje no la necesita.
- NUNCA menciones membresía, pagos ni ninguna información comercial.
- El mensaje debe invitar claramente a responder (ej. "responde sí") para continuar.
- Responde EXCLUSIVAMENTE con el texto final del mensaje — sin JSON, sin comillas envolventes, sin explicaciones, sin markdown.
PROMPT;
    }

    /**
     * Cualquier señal de que la respuesta no cumple el contrato (vacía,
     * parece JSON/markdown, demasiado larga) degrada al fallback — nunca se
     * "arregla" ni se trunca el texto de la IA.
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
