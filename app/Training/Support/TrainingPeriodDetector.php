<?php

namespace App\Training\Support;

/**
 * Hito — Historial de progreso por período. Heurística DETERMINISTA (sin
 * IA, sin llamada LLM adicional) que detecta, en el texto crudo del
 * mensaje, una expresión de período INEQUÍVOCA — mismo estilo que
 * `App\CustomerCare\Support\FaqRelevanceDetector` (normalización +
 * constantes de frases + `str_contains()`).
 *
 * Deliberadamente ESTRECHO en recall — al revés que `FaqRelevanceDetector`
 * (deliberadamente amplio): un falso NEGATIVO aquí es inofensivo (el LLM
 * sigue viendo los 3 períodos reales calculados por
 * `App\Training\Context\CoachContextProvider` y usa el fallback ya
 * documentado, `last_4_weeks`); un falso POSITIVO con una frase ambigua
 * resaltaría el período equivocado con aparente autoridad, que es peor.
 * Por eso cubre ÚNICAMENTE las 3 frases explícitamente inequívocas del
 * diseño aprobado — deliberadamente NO incluye "el último mes" (podría
 * significar "los últimos 30 días" o "el mes calendario anterior", dos
 * cosas distintas de `last_4_weeks`) ni "en general" (demasiado vago para
 * mapear con seguridad a `all_time`).
 *
 * Este detector NUNCA decide qué datos se calculan — `CoachContextProvider`
 * calcula los 3 períodos SIEMPRE, sin importar lo que este detector
 * encuentre; solo decide qué línea resaltar en `CoachFactsFormatter` como
 * "la que el usuario pidió". No requiere una segunda llamada de IA ni una
 * capa general de queries (ver evaluación de diseño de este hito).
 */
class TrainingPeriodDetector
{
    private const CURRENT_WEEK_PHRASES = [
        'esta semana',
    ];

    private const LAST_4_WEEKS_PHRASES = [
        'últimas 4 semanas',
        'ultimas 4 semanas',
    ];

    private const ALL_TIME_PHRASES = [
        'en total',
        'desde que empecé',
        'desde que empece',
    ];

    /**
     * @return ?string  'current_week'|'last_4_weeks'|'all_time'|null
     */
    public function detect(string $messageBody): ?string
    {
        $normalized = mb_strtolower(trim($messageBody));

        if ($normalized === '') {
            return null;
        }

        foreach (self::CURRENT_WEEK_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'current_week';
            }
        }

        foreach (self::LAST_4_WEEKS_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'last_4_weeks';
            }
        }

        foreach (self::ALL_TIME_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'all_time';
            }
        }

        return null;
    }
}
