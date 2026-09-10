<?php

namespace App\Training\Enums;

/**
 * H16.2 Fase 1 — vocabulario cerrado de la intención de cierre de una
 * sesión, calculado EXCLUSIVAMENTE por `App\Training\Handlers\TrainingHandler`
 * (código, nunca la IA) DESPUÉS de que `App\Training\Support\ExecutionReportRecorder::record()`
 * ya decidió el estado real (`ExecutionReportOutcome`). `SessionCloseMessageComposer`
 * recibe este valor ya resuelto — nunca lo infiere ni lo reinterpreta, solo
 * redacta el mensaje correspondiente.
 *
 * Solo se calcula cuando el turno fue un intento EXPLÍCITO de cierre
 * (`session_finished=true` en la extracción de `ExecutionReportService`) —
 * un reporte normal (`session_finished=false`) nunca produce ninguno de
 * estos valores, ni siquiera cuando ese reporte completa la sesión de forma
 * implícita (ver docblock de `TrainingHandler::recordExecutionReport()`).
 */
enum SessionCloseIntent: string
{
    /**
     * El usuario intentó cerrar la sesión, pero todavía existen ejercicios
     * genuinamente sin ningún registro (`ExerciseLog`) — la sesión NUNCA se
     * marca completada en este caso (ver el fix de
     * `ExecutionReportRecorder::maybeCompleteSession()`).
     */
    case BlockedStillPending = 'blocked_still_pending';

    /**
     * La sesión cerró y TODOS los ejercicios quedaron realmente realizados
     * (ningún `ExerciseLog` sin `ExerciseSet`).
     */
    case SuccessFull = 'success_full';

    /**
     * La sesión cerró, pero al menos un ejercicio quedó registrado como NO
     * realizado (`ExerciseLog` sin ningún `ExerciseSet` — mismo criterio de
     * `HistoryExerciseOutcome::Skipped` ya usado en `CoachContextProvider`/
     * `TrainingHistoryContextProvider`) — nunca "sin reportar", eso sería
     * `BlockedStillPending`.
     */
    case SuccessPartial = 'success_partial';
}
