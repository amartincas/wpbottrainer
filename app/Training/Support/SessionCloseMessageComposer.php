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
     * H16.2 Fase 1.2 (revisión) — reglas por intención, en la redacción
     * exacta aprobada. Nunca se reinterpretan ni se combinan entre sí — el
     * `match` en `buildPrompt()`/`fallbackFor()` garantiza que solo una
     * aplique por invocación.
     */
    private const BLOCKED_STILL_PENDING_RULE = <<<'RULE'
Si "session_close_intent" es "BlockedStillPending":
- El usuario indicó que terminó la sesión, pero todavía existen ejercicios sin reportar.
- NO digas que la sesión está completada.
- NO digas que terminó el entrenamiento.
- Explica brevemente que todavía quedan ejercicios pendientes.
- Menciona los nombres de los ejercicios contenidos en "pending_exercises".
- Orienta al usuario hacia "next_exercise", que es el siguiente ejercicio que debe realizar/reportar.
- No inventes ejercicios, resultados, series, repeticiones, pesos ni información que no esté en los hechos proporcionados.
- Mantén un tono de entrenador: natural, breve y útil. No suenes como un mensaje de error del sistema.
RULE;

    private const SUCCESS_FULL_RULE = <<<'RULE'
Si "session_close_intent" es "SuccessFull":
- Todos los ejercicios fueron realizados y registrados.
- Confirma que la sesión quedó completada.
- Puedes reconocer brevemente el trabajo realizado, usando "logged_summaries".
- No menciones ejercicios pendientes.
- No inventes resultados ni hagas afirmaciones sobre rendimiento que no estén en los hechos proporcionados.
- El mensaje debe sentirse como el cierre natural de un entrenador, no como un reporte administrativo.
RULE;

    private const SUCCESS_PARTIAL_RULE = <<<'RULE'
Si "session_close_intent" es "SuccessPartial":
- La sesión quedó cerrada, pero uno o más ejercicios fueron registrados como no realizados (ver "logged_summaries").
- Reconoce que la sesión quedó registrada sin celebrar que un ejercicio no se haya realizado.
- Puedes mencionar brevemente que hubo ejercicios que no se pudieron realizar.
- NO digas que quedaron ejercicios pendientes: ya fueron procesados.
- No confundas "Skipped" (ya procesado, no realizado) con "Unreported" (todavía sin reportar) — ver REGLA FUNDAMENTAL.
RULE;

    /**
     * @param  array{intent: string, contact_name: ?string, pending_exercises: string[], next_exercise: ?string, logged_summaries: string[], skipped_exercise_names?: string[]}  $facts
     *         `skipped_exercise_names` es de uso EXCLUSIVO del fallback
     *         determinista (`fallbackFor()`) — nunca se expone en el bloque
     *         de HECHOS del prompt; `logged_summaries` ya comunica en
     *         lenguaje natural qué quedó sin realizar (ver
     *         `ExecutionReportRecorder::summaryOf()`, H16.2 Fase 1.2).
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
        $rule = match ($intent) {
            SessionCloseIntent::BlockedStillPending => self::BLOCKED_STILL_PENDING_RULE,
            SessionCloseIntent::SuccessFull => self::SUCCESS_FULL_RULE,
            SessionCloseIntent::SuccessPartial => self::SUCCESS_PARTIAL_RULE,
        };
        $factsBlock = $this->formatFacts($intent, $facts);

        return <<<PROMPT
Eres el entrenador personal de WpbotTrainer, escribiendo por WhatsApp.

El campo "session_close_intent" es determinado exclusivamente por el sistema después de registrar el reporte del usuario. Debes respetarlo y NO reinterpretarlo.

{$rule}

REGLA FUNDAMENTAL:
"BlockedStillPending" significa: hay ejercicios que todavía NO han sido reportados.
"SuccessPartial" significa: todos los ejercicios ya fueron procesados, aunque alguno haya sido marcado como no realizado.
Nunca confundas estos dos estados.

HECHOS DISPONIBLES (única fuente de verdad — utiliza ÚNICAMENTE estos hechos para redactar el mensaje):
{$factsBlock}

REGLAS DURAS, INAMOVIBLES:
- NUNCA inventes un ejercicio, una serie, una repetición, una carga ni ningún dato que no aparezca en los hechos.
- NUNCA decidas tú si la sesión está completa, parcial o pendiente — eso ya está decidido, solo redactas cómo se dice.
- Puedes usar "contact_name" con naturalidad si está disponible, sin abusar.
- Sé breve (2-4 frases como máximo), directo, sin frases motivacionales vacías ni exceso de emojis.
- Responde EXCLUSIVAMENTE con el texto final del mensaje — sin JSON, sin comillas envolventes, sin explicaciones, sin markdown.
PROMPT;
    }

    /**
     * Renderiza EXACTAMENTE los 5 hechos aprobados (`session_close_intent`,
     * `contact_name`, `pending_exercises`, `next_exercise`,
     * `logged_summaries`) — nunca `skipped_exercise_names` (uso interno del
     * fallback, ver docblock de `compose()`). `pending_exercises`/
     * `next_exercise` solo tienen sentido para `BlockedStillPending` — se
     * omiten para los otros dos intents, mismo criterio de "ausencia =
     * no corresponde" ya usado en el resto de la capa conversacional.
     */
    private function formatFacts(SessionCloseIntent $intent, array $facts): string
    {
        $lines = ['session_close_intent: '.$intent->value];

        if (($facts['contact_name'] ?? null) !== null) {
            $lines[] = 'contact_name: '.$facts['contact_name'];
        }

        if ($intent === SessionCloseIntent::BlockedStillPending) {
            $lines[] = 'pending_exercises: '.implode(', ', $facts['pending_exercises']);
            $lines[] = 'next_exercise: '.($facts['next_exercise'] ?? ($facts['pending_exercises'][0] ?? ''));
        }

        if ($facts['logged_summaries'] !== []) {
            $lines[] = 'logged_summaries: '.implode('; ', $facts['logged_summaries']);
        }

        return implode("\n", $lines);
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
                implode(', ', $facts['pending_exercises']),
            ),
            SessionCloseIntent::SuccessFull => '🏁 ¡Entrenamiento completado! Buen trabajo. Escríbeme cuando quieras tu próximo entrenamiento.',
            SessionCloseIntent::SuccessPartial => sprintf(
                '🏁 Cerré tu entrenamiento de hoy. Quedó pendiente: %s — lo retomamos otro día. Escríbeme cuando quieras el siguiente.',
                implode(', ', $facts['skipped_exercise_names'] ?? []),
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
