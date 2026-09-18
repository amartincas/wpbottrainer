<?php

namespace App\Training\Support;

use Carbon\CarbonImmutable;

/**
 * Hito — Historial de progreso por período. Rango temporal YA resuelto, en
 * UTC (mismo criterio que `ReminderTimeResolution::fireAt`: la IA nunca
 * calcula fechas, `TrainingPeriodResolver` es la única autoridad que
 * produce este objeto). Inicio inclusivo, fin exclusivo.
 *
 * `start` es `null` únicamente para `all_time` (sin cota inferior) — nunca
 * para `current_week`/`last_4_weeks`, que siempre tienen un límite real.
 *
 * `label` es el vocabulario cerrado que originó el período
 * (`current_week`/`last_4_weeks`/`all_time`), conservado solo para
 * trazabilidad (ej. qué línea de `CoachFactsFormatter` corresponde a este
 * período) — nunca se vuelve a interpretar a partir de él.
 */
final readonly class TrainingPeriod
{
    public function __construct(
        public string $label,
        public ?CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}
}
