<?php

namespace App\Training\Enums;

/**
 * Bloque 7 (D050) — compara la intensidad (carga o duración, según
 * `TrackingType`) de la ejecución `Performed` cronológicamente más reciente
 * (E) contra la mejor intensidad entre las ejecuciones `Performed`
 * ANTERIORES a E (nunca incluye a E). Deliberadamente NO se llama "Rising":
 * igualar el propio mejor rendimiento reciente (`AtRecentBest`) es un
 * resultado distinto de superarlo (`Improved`), aunque ambos puedan ser
 * evidencia válida de progreso (ver `ProgressionEvaluator`).
 *
 * - Improved: intensidad de E > mejor intensidad previa a E.
 * - AtRecentBest: intensidad de E == mejor intensidad previa a E.
 * - BelowRecentBest: intensidad de E < mejor intensidad previa a E.
 * - Unknown: E no tiene intensidad disponible (sin buscar hacia atrás); o E
 *   sí la tiene pero no existe ninguna ejecución anterior con dato de esa
 *   magnitud para comparar (sin línea base).
 * - NotApplicable: ninguna ejecución de la ventana (ni E ni ninguna
 *   anterior) tiene dato de esa magnitud.
 */
enum ProgressionIntensitySignal: string
{
    case Improved = 'improved';
    case AtRecentBest = 'at_recent_best';
    case BelowRecentBest = 'below_recent_best';
    case Unknown = 'unknown';
    case NotApplicable = 'not_applicable';
}
