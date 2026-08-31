<?php

namespace App\Training\Enums;

/**
 * Vocabulario cerrado para el esfuerzo percibido expresado en lenguaje
 * natural (Hito 6) — "me pareció fácil", "estuvo pesado", "muy difícil". El
 * LLM solo clasifica el mensaje del usuario en una de estas categorías; la
 * conversión a un RPE numérico (1-10) es una tabla determinista
 * (App\Training\Support\ExecutionReportService::mapRpeCategory()), nunca un
 * número que el LLM inventa libremente a partir de la categoría.
 */
enum RpeCategory: string
{
    case VeryEasy = 'very_easy';
    case Easy = 'easy';
    case Moderate = 'moderate';
    case Hard = 'hard';
    case VeryHard = 'very_hard';
}
