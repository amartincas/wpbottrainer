<?php

namespace App\Training\Context;

use App\Training\Support\ProgressionEvaluation;
use App\Training\Support\TrainingHistoryContext;

/**
 * Bloque 9 (D052) — contexto de dominio estructurado para UNA interacción
 * de Coach/conversación. Deliberadamente en `App\Training\Context`, NO en
 * `App\Training\Memory`: esa carpeta representa memoria/historial ya
 * persistido (`TrainingProfileContextProvider`, `ActiveWorkoutSessionContextProvider`);
 * `CoachContext` es una composición temporal de hechos ya existentes para
 * un turno, nunca memoria en sí misma. NO se persiste (ver D052) — se
 * reconstruye en cada turno.
 *
 * No copia ni reinterpreta fuentes: `historyContext` es el objeto completo
 * de `TrainingHistoryContext` (D049), `progressionEvaluations` son
 * `ProgressionEvaluation` (D050) ya calculadas con ese mismo contexto — sin
 * ninguna consulta ni lógica propia de recálculo.
 *
 * `recentMessages` es contexto lingüístico, NUNCA una fuente de hechos —
 * ver `CoachFactsFormatter` para cómo se delimita explícitamente en el
 * prompt frente a los HECHOS estructurados.
 *
 * `pendingReminderSuggestion` (Hito 10, D053, corrección post-revisión) es,
 * en cambio, un HECHO estructurado más — igual que `progressionEvaluations`
 * — precisamente para NO depender de `recentMessages` al identificar una
 * `ReminderSuggestion` pendiente (puede seguir `pending` hasta 24h, mucho
 * más que la ventana de 10 mensajes). Reutiliza literalmente
 * `ReminderSuggestion::activePendingFor()` — ver `CoachContextProvider`.
 *
 * `needsConversationReinforcement` (H16.1, Cambio 3) — HECHO estructurado
 * más, mismo criterio: `CoachContextProvider` ya determinó, sin IA, leyendo
 * `TrainingProfile.coach_conversation_reinforced`, si corresponde pedirle a
 * la IA que incluya el refuerzo de conversación libre dentro de
 * `training_reply` — la IA nunca decide esto por su cuenta.
 */
final readonly class CoachContext
{
    /**
     * Nota: NO incluye el mensaje actual del usuario — `CoachService`/
     * `ExecutionReportService` ya lo reciben como su propio parámetro
     * explícito (`$messageBody`), igual que el resto del código existente
     * (`extractReport(string $messageBody, ...)`) — duplicarlo aquí sería
     * una segunda copia de la misma cadena, con riesgo de desincronizarse.
     *
     * @param  array<string, mixed>  $profileSnapshot  igual forma que TrainingHistoryContext->currentProfileSnapshot
     * @param  array<int, ProgressionEvaluation>  $progressionEvaluations  keyed por exerciseId, solo de los ejercicios de currentSession
     * @param  array<int, array{role: string, content: string}>  $recentMessages
     * @param  ?array<int, CoachFaqCandidate>  $activeFaqs  Hito 14 — `null` si el gate
     *         determinista (`FaqRelevanceDetector`/`CustomerServiceEscalationDetector`)
     *         NO se activó este turno (CoachService ni construye el bloque de FAQ del
     *         prompt); `[]` si el gate SÍ se activó pero `FaqMatcher::retrieveCandidates()`
     *         no encontró ningún candidato (el bloque SÍ se incluye, con la variante de
     *         "sin candidatos" — la ausencia de candidatos nunca equivale a ausencia del
     *         bloque de evaluación); no vacío si hay candidatos reales. Ver docs/DECISIONS.md.
     */
    public function __construct(
        public array $profileSnapshot,
        public ?CoachSessionSnapshot $currentSession,
        public TrainingHistoryContext $historyContext,
        public array $progressionEvaluations,
        public array $recentMessages,
        public ?PendingReminderSuggestion $pendingReminderSuggestion = null,
        public ?array $activeFaqs = null,
        public bool $needsConversationReinforcement = false,
    ) {}
}
