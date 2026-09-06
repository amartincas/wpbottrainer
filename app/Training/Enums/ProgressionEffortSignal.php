<?php

namespace App\Training\Enums;

/**
 * Bloque 7 (D050) — banda de esfuerzo percibido derivada ÚNICAMENTE del RPE
 * (escala 1-10, ver `ExecutionReportService`) de la ejecución `Performed`
 * cronológicamente más reciente (E). Nunca se busca RPE en ejecuciones
 * anteriores para completar este dato — si E no registró RPE, el resultado
 * es `Unknown`, punto.
 *
 * - Controlled: RPE de E entre 1 y 6.
 * - Elevated: RPE de E entre 7 y 8.
 * - Excessive: RPE de E entre 9 y 10.
 * - Unknown: E no registró RPE.
 */
enum ProgressionEffortSignal: string
{
    case Controlled = 'controlled';
    case Elevated = 'elevated';
    case Excessive = 'excessive';
    case Unknown = 'unknown';
}
