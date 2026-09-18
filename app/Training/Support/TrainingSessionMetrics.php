<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutSessionStatus;

/**
 * Hito — Historial de progreso por período. Métricas históricas REALES —
 * independientes de `MAX_SESSIONS`/`WINDOW_WEEKS` de
 * `TrainingHistoryContextProvider` (ese componente existe para acotar el
 * CONTEXTO de razonamiento del Coach — progresión, anti-repetición — nunca
 * para responder "cuántas sesiones completaste"; se preserva sin cambios).
 * Cada consulta aquí es un `COUNT()` real, sin `LIMIT`, sobre el período
 * exacto solicitado.
 *
 * Pertenencia a un período: por `completed_at`, NUNCA `scheduled_at` (ver
 * docs/DECISIONS.md de este hito) — una sesión cuenta en el período en que
 * REALMENTE se completó, no en el que fue programada/prescrita. Toda
 * `WorkoutSession` con `status=Completed` tiene `completed_at` no nulo —
 * ambos se escriben juntos, atómicamente, en el único punto de todo el
 * código que hace esta transición
 * (`ExecutionReportRecorder::maybeCompleteSession()`) — nunca hace falta un
 * filtro adicional de `completed_at IS NOT NULL`, el filtro `status=Completed`
 * ya lo garantiza.
 *
 * Nunca construye una consulta genérica a partir de parámetros de la IA —
 * es la ÚNICA operación de este tipo, escrita a mano, parametrizada
 * únicamente por un `TrainingPeriod` ya resuelto (ver evaluación de diseño
 * de este hito: no es una capa general de queries).
 */
class TrainingSessionMetrics
{
    public function completedCount(Contact $contact, TrainingPeriod $period): int
    {
        $query = WorkoutSession::where('contact_id', $contact->id)
            ->where('status', WorkoutSessionStatus::Completed)
            ->where('completed_at', '<', $period->end);

        if ($period->start !== null) {
            $query->where('completed_at', '>=', $period->start);
        }

        return $query->count();
    }
}
