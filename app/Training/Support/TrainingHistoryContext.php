<?php

namespace App\Training\Support;

/**
 * Bloque 6 — resultado de `TrainingHistoryContextProvider::build()`. Capa
 * de LECTURA pura: hechos y métricas ya ocurridos, nunca una decisión.
 *
 * `currentProfileSnapshot` usa EXACTAMENTE el mismo subconjunto de campos
 * que `TrainingEngine` ya consume de `TrainingProfile` (verificado por
 * inspección directa del código, no supuesto) — nunca una representación
 * nueva o más amplia del perfil.
 *
 * `activeSafetyBodyRegions` es el resultado literal de
 * `SafetyRestrictionResolver::activeSafetyBodyRegions()` — nunca
 * recalculado aquí, para no duplicar lógica de seguridad.
 *
 * NO existe ninguna integración con `TrainingEngine`/`ProgressionEvaluator`/
 * `CoachService` en este bloque — este DTO es exclusivamente la capa
 * preparatoria de contexto histórico (ver docs/DECISIONS.md D049).
 */
final readonly class TrainingHistoryContext
{
    /**
     * @param  array<int, HistorySessionEntry>  $sessions
     * @param  array<string, mixed>  $currentProfileSnapshot
     * @param  array<int, string>  $activeSafetyBodyRegions
     */
    public function __construct(
        public int $windowSessionsCount,
        public int $windowWeeks,
        public array $sessions,
        public HistoryAggregates $aggregates,
        public array $currentProfileSnapshot,
        public array $activeSafetyBodyRegions,
    ) {}
}
