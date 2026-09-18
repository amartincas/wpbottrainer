<?php

namespace App\Training\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Hito — Historial de progreso por período. Autoridad DETERMINISTA que
 * traduce un label de vocabulario cerrado (`current_week`/`last_4_weeks`/
 * `all_time`) a un `TrainingPeriod` real, con `start`/`end` concretos en
 * UTC — mismo patrón exacto que `ReminderTimeResolver`: ninguna aritmética
 * de fechas vive en el prompt ni la decide el LLM; esta clase es la única
 * que la calcula.
 *
 * Un label desconocido, o `null` (el usuario no especificó período), cae a
 * `last_4_weeks` — el mismo comportamiento por defecto que el sistema ya
 * tenía antes de este hito, nunca un error ni un período vacío inesperado.
 *
 * `current_week`: lunes 00:00:00 (inicio de semana ISO-8601, default de
 * Carbon — no hay ninguna configuración de `weekStartsAt` en el proyecto)
 * hasta el lunes siguiente 00:00:00, EXCLUSIVO — nunca `endOfWeek()`
 * (23:59:59.999999 del domingo), que sería más frágil ante precisión de
 * microsegundos. Ambos límites se calculan primero en la timezone del
 * Tenant (`$timezone`, resuelta vía `TimezoneResolver::resolve()` — nunca
 * leída directamente por el llamador) y luego se convierten a UTC — el
 * corte de semana debe decidirse en hora LOCAL, nunca comparando fechas en
 * UTC crudo (una sesión completada a las 23:50 UTC del domingo puede ser
 * lunes en Bogotá).
 */
class TrainingPeriodResolver
{
    private const LAST_PERIOD_WEEKS = 4;

    public function resolve(?string $label, string $timezone, CarbonInterface $now): TrainingPeriod
    {
        $nowLocal = CarbonImmutable::instance($now)->setTimezone($timezone);

        return match ($label) {
            'current_week' => new TrainingPeriod(
                'current_week',
                $nowLocal->startOfWeek()->setTimezone('UTC'),
                $nowLocal->startOfWeek()->addWeek()->setTimezone('UTC'),
            ),
            'all_time' => new TrainingPeriod(
                'all_time',
                null,
                $nowLocal->setTimezone('UTC'),
            ),
            default => new TrainingPeriod(
                'last_4_weeks',
                $nowLocal->subWeeks(self::LAST_PERIOD_WEEKS)->setTimezone('UTC'),
                $nowLocal->setTimezone('UTC'),
            ),
        };
    }
}
