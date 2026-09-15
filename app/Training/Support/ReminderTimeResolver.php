<?php

namespace App\Training\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Hito 10 — autoridad DETERMINISTA que convierte fragmentos de lenguaje
 * natural ya extraídos por la IA (día/hora/recurrencia, vocabulario cerrado)
 * en un `fire_at` UTC concreto. La IA nunca hace aritmética de fechas (mismo
 * principio ya usado en `CoachFactsFormatter`) — esta clase es la única que
 * calcula fechas reales. Un resultado `null` significa "no hay suficiente
 * información / es ambiguo" — el llamador debe pedir una aclaración, nunca
 * asumir un valor por defecto.
 */
class ReminderTimeResolver
{
    private const WEEKDAYS = [
        'sunday' => CarbonInterface::SUNDAY,
        'monday' => CarbonInterface::MONDAY,
        'tuesday' => CarbonInterface::TUESDAY,
        'wednesday' => CarbonInterface::WEDNESDAY,
        'thursday' => CarbonInterface::THURSDAY,
        'friday' => CarbonInterface::FRIDAY,
        'saturday' => CarbonInterface::SATURDAY,
    ];

    /**
     * @param  ?string  $day  "monday".."sunday" | "tomorrow" | "today" | null
     * @param  ?string  $time  "HH:MM" (24h) | null
     * @param  bool  $recurring  true = "todos los X"; false = ocurrencia única
     * @param  string  $timezone  resuelta vía TimezoneResolver::resolve() — nunca leída directamente por el llamador
     */
    public function resolve(?string $day, ?string $time, bool $recurring, string $timezone, CarbonInterface $now): ?ReminderTimeResolution
    {
        if ($time === null || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        $nowLocal = CarbonImmutable::instance($now)->setTimezone($timezone);
        $weekday = self::WEEKDAYS[$day] ?? null;

        // H16.2 Fase 1.3 (auditoría de flujo conversacional, Caso 2) — el
        // usuario dio una hora sin ningún día ("recuérdame a las 7"): en vez
        // de tratarlo como ambiguo, se asume HOY si esa hora todavía no pasó,
        // o MAÑANA si ya pasó — usando el mismo $nowLocal que el resto de
        // esta clase ya calcula. Nunca delegado a la IA: el LLM solo extrae
        // que no hubo día explícito (day=null), esta clase decide la fecha
        // real. `$recurring` sigue rechazado más abajo cuando no hay un día
        // de la semana real (weekday===null) — este cambio no lo afecta.
        $candidateDate = match (true) {
            $weekday !== null => $this->nextOccurrenceOfWeekday($nowLocal, $weekday),
            $day === 'today' => $nowLocal->startOfDay(),
            $day === 'tomorrow' => $nowLocal->startOfDay()->addDay(),
            $day === null => $this->inferImplicitDay($nowLocal, $hour, $minute),
            default => null,
        };

        // Un recordatorio recurrente necesita un día de la semana real — "todos
        // los hoy"/"todos los mañana" no tiene sentido y nunca se adivina.
        if ($candidateDate === null || ($recurring && $weekday === null)) {
            return null;
        }

        $candidateDateTime = $candidateDate->setTime($hour, $minute, 0);

        if ($candidateDateTime->lessThanOrEqualTo($nowLocal)) {
            // Un día de la semana concreto que ya pasó hoy se interpreta como
            // la próxima semana (comportamiento esperado, nunca en el
            // pasado); "hoy"/"mañana" ya vencidos son genuinamente ambiguos
            // — nunca se corrigen en silencio, se pide aclaración.
            if ($weekday === null) {
                return null;
            }

            $candidateDateTime = $candidateDateTime->addWeek();
        }

        $recurrence = $recurring
            ? ['freq' => 'weekly', 'day_of_week' => $weekday, 'time' => $time]
            : null;

        return new ReminderTimeResolution($candidateDateTime->setTimezone('UTC'), $recurrence);
    }

    private function nextOccurrenceOfWeekday(CarbonImmutable $fromLocal, int $weekday): CarbonImmutable
    {
        $candidate = $fromLocal->startOfDay();

        while ($candidate->dayOfWeek !== $weekday) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    /**
     * H16.2 Fase 1.3 (Caso 2) — el usuario dio una hora sin día ("a las 7").
     * HOY si esa hora todavía no ha pasado en $nowLocal; MAÑANA si ya pasó o
     * es exactamente la hora actual (misma convención de "igual = ya pasó"
     * que el resto de esta clase usa en la línea de `lessThanOrEqualTo` de
     * arriba). Devuelve solo la FECHA (medianoche local) — resolve() le
     * aplica la hora exacta justo después, igual que a cualquier otra rama.
     */
    private function inferImplicitDay(CarbonImmutable $nowLocal, int $hour, int $minute): CarbonImmutable
    {
        $todayAtRequestedTime = $nowLocal->setTime($hour, $minute, 0);

        return $todayAtRequestedTime->greaterThan($nowLocal)
            ? $nowLocal->startOfDay()
            : $nowLocal->startOfDay()->addDay();
    }
}
