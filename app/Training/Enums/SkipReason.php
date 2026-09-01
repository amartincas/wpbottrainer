<?php

namespace App\Training\Enums;

/**
 * Hito 8.3 — vocabulario cerrado para por qué un ejercicio no se realizó.
 * Mismo patrón que RpeCategory: la IA solo clasifica dentro de este
 * conjunto cerrado si el usuario dio o insinuó una razón — nunca inventa
 * una si no se mencionó (queda null en ese caso).
 */
enum SkipReason: string
{
    case CantDo = 'cant_do';
    case DontWant = 'dont_want';
    case NoTime = 'no_time';
    case Other = 'other';
}
