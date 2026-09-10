<?php

namespace App\Training\Support;

use App\Factories\AIServiceFactory;
use App\Models\Tenant;
use App\Training\Enums\SessionCloseIntent;
use Illuminate\Support\Facades\Log;

/**
 * H16.2 Fase 1 — mismo contrato exacto que `TrialEndedMessageComposer`/
 * `ReminderMessageComposer`: la aplicación ya decidió TODO antes de llegar
 * aquí (`SessionCloseIntent`, calculado por `TrainingHandler` DESPUÉS de que
 * `ExecutionReportRecorder::record()` ya resolvió el estado real) — esta
 * clase ÚNICAMENTE redacta CÓMO se dice. Nunca decide si la sesión está
 * completa, cuáles ejercicios faltan, ni si corresponde cerrar.
 *
 * Se invoca EXCLUSIVAMENTE cuando el turno fue un intento explícito de
 * cierre (`session_finished=true`) — un reporte normal nunca llega aquí, ni
 * siquiera cuando completa la sesión de forma implícita (ver
 * `TrainingHandler::recordExecutionReport()`). Es, por tanto, como máximo la
 * SEGUNDA llamada de IA del turno, nunca una tercera, y nunca ocurre en un
 * turno sin sesión activa.
 *
 * `validate()` va más allá del formato genérico (vacío/demasiado largo/
 * parece JSON) ya usado por el resto de composers: además rechaza cualquier
 * salida que contradiga semánticamente la intención ya decidida (lista
 * cerrada de frases, mismo criterio que `SafetySignalDetector::PATTERNS` —
 * nunca NLP, nunca heurística difusa) — la IA nunca puede afirmar un cierre
 * que no ocurrió, ni pendientes que ya no existen.
 */
class SessionCloseMessageComposer
{
    private const MAX_LENGTH = 400;

    /**
     * Frases que NUNCA pueden aparecer cuando la sesión NO se cerró — si la
     * IA las produce igual, se descarta y se usa el fallback determinista.
     */
    private const CLOSURE_CLAIM_PATTERNS = [
        'sesión completada', 'sesion completada', 'entrenamiento completado',
        'terminaste', 'completaste tu entrenamiento', 'buen trabajo, eso es todo',
        '🏁',
    ];

    /**
     * Frases que NUNCA pueden aparecer cuando la sesión SÍ se cerró (con
     * éxito total o parcial) — no puede quedar ningún ejercicio "sin
     * reportar" cuando el código ya determinó que no queda ninguno.
     */
    private const PENDING_CLAIM_PATTERNS = [
        'todavía tienes pendientes', 'todavia tienes pendientes', 'te falta',
        '¿a cuál ejercicio te refieres', '¿a cual ejercicio te refieres',
        'quedan pendientes',
    ];

    /**
     * @param  array{intent: string, contact_name: ?string, pending_exercise_names: string[], logged_summaries: string[], skipped_exercise_names: string[]}  $facts
     */
    public function compose(SessionCloseIntent $intent, array $facts, Tenant $tenant): string
    {
        try {
            $ai = AIServiceFactory::make($tenant);
            $raw = $ai->getResponse('Redacta el mensaje ahora.', $this->buildPrompt($intent, $facts), []);

            return $this->validate($raw, $intent) ?? $this->fallbackFor($intent, $facts);
        } catch (\Throwable $e) {
            Log::warning('SESSION_CLOSE_MESSAGE_COMPOSE_FAILED', [
                'tenant_id' => $tenant->id,
                'intent' => $intent->value,
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackFor($intent, $facts);
        }
    }

    private function buildPrompt(SessionCloseIntent $intent, array $facts): string
    {
        $name = $facts['contact_name'] ?? null;
        $greeting = $name !== null ? " El usuario se llama {$name} — puedes usar su nombre con naturalidad, sin abusar." : '';

        $stateInstructions = match ($intent) {
            SessionCloseIntent::BlockedStillPending => 'El usuario intentó cerrar su entrenamiento (dijo algo como "ya terminé"), pero el sistema determinó que TODAVÍA tiene ejercicios pendientes de reportar: '
                .implode(', ', $facts['pending_exercise_names']).'. NUNCA afirmes que la sesión terminó o se completó — todavía NO. Reconoce el intento, indica con claridad (usando los nombres exactos de arriba) cuáles ejercicios faltan, y anímalo a seguir.',
            SessionCloseIntent::SuccessFull => 'El usuario completó TODOS los ejercicios de su entrenamiento de hoy. Esto es lo que se registró: '
                .implode('; ', $facts['logged_summaries']).'. Reconoce el logro, sé breve y directo, sin exagerar, e indica que puede escribir cuando quiera su próximo entrenamiento. NUNCA menciones ningún ejercicio pendiente — no quedó ninguno.',
            SessionCloseIntent::SuccessPartial => 'El usuario cerró su entrenamiento de hoy sin completar todo. Esto es lo que SÍ se registró: '
                .implode('; ', $facts['logged_summaries']).'. Esto quedó marcado como no realizado hoy: '
                .implode(', ', $facts['skipped_exercise_names']).'. Reconoce lo que sí hizo, menciona con naturalidad (sin regañar ni sonar decepcionado) que lo demás queda para otra sesión.',
        };

        return <<<PROMPT
Eres el entrenador personal de WpbotTrainer, escribiendo por WhatsApp. Tu ÚNICA tarea es redactar UN mensaje breve, natural y propio de un entrenador — nunca de un sistema — usando EXCLUSIVAMENTE este hecho ya determinado por el sistema:{$greeting}

{$stateInstructions}

REGLAS DURAS, INAMOVIBLES:
- NUNCA inventes un ejercicio, una serie, una repetición, una carga ni ningún dato que no aparezca arriba.
- NUNCA decidas tú si la sesión está completa, parcial o pendiente — eso ya está decidido, solo redactas cómo se dice.
- Sé breve (2-4 frases como máximo), directo, sin frases motivacionales vacías ni exceso de emojis.
- Responde EXCLUSIVAMENTE con el texto final del mensaje — sin JSON, sin comillas envolventes, sin explicaciones, sin markdown.
PROMPT;
    }

    /**
     * Fallback determinista por intención — usa ÚNICAMENTE los hechos ya
     * resueltos por código, nunca depende de que la IA haya respondido algo.
     */
    private function fallbackFor(SessionCloseIntent $intent, array $facts): string
    {
        return match ($intent) {
            SessionCloseIntent::BlockedStillPending => sprintf(
                '¡Casi! 💪 Todavía te faltan estos ejercicios: %s. Cuando los tengas, cuéntame y cerramos el entrenamiento.',
                implode(', ', $facts['pending_exercise_names']),
            ),
            SessionCloseIntent::SuccessFull => '🏁 ¡Entrenamiento completado! Buen trabajo. Escríbeme cuando quieras tu próximo entrenamiento.',
            SessionCloseIntent::SuccessPartial => sprintf(
                '🏁 Cerré tu entrenamiento de hoy. Quedó pendiente: %s — lo retomamos otro día. Escríbeme cuando quieras el siguiente.',
                implode(', ', $facts['skipped_exercise_names']),
            ),
        };
    }

    /**
     * Formato genérico (vacío/demasiado largo/parece JSON, mismo criterio
     * que el resto de composers) MÁS la validación semántica por intención
     * — nunca se "arregla" ni se trunca el texto de la IA, cualquier
     * violación degrada directamente al fallback.
     */
    private function validate(string $raw, SessionCloseIntent $intent): ?string
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

        $normalized = mb_strtolower($text);

        if ($intent === SessionCloseIntent::BlockedStillPending) {
            foreach (self::CLOSURE_CLAIM_PATTERNS as $pattern) {
                if (str_contains($normalized, mb_strtolower($pattern))) {
                    return null;
                }
            }
        } else {
            foreach (self::PENDING_CLAIM_PATTERNS as $pattern) {
                if (str_contains($normalized, mb_strtolower($pattern))) {
                    return null;
                }
            }
        }

        return $text;
    }
}
