<?php

namespace App\CustomerCare\Support;

/**
 * Hito 14 — heurística determinista (sin IA, sin llamada LLM adicional)
 * para decidir si un mensaje "parece" una pregunta que podría responderse
 * con una FAQ. Deliberadamente amplia en recall (ver docs/DECISIONS.md) —
 * un falso positivo aquí solo implica incluir el bloque de FAQ en el
 * prompt que de todas formas ya se está construyendo (camino de
 * interrupción) o, en el camino independiente, gastar como máximo una
 * llamada LLM que de todas formas se resuelve de forma segura (ver
 * FaqMatcher::sanitize()) — nunca una respuesta inventada.
 *
 * Reutilizada tanto por `FaqLikelyIntentClassifier` (Router, camino
 * independiente) como por `App\Training\Context\CoachContextProvider` (el
 * gate que decide si construir el bloque de FAQ del prompt de Coach) —
 * una sola fuente de verdad, evita que ambos consumidores diverjan.
 */
class FaqRelevanceDetector
{
    private const INTERROGATIVE_WORDS = [
        'cómo', 'como', 'cuánto', 'cuanto', 'cuánta', 'cuanta', 'cuántos', 'cuantos',
        'cuántas', 'cuantas', 'cuándo', 'cuando', 'dónde', 'donde', 'adónde', 'adonde',
        'qué', 'que', 'cuál', 'cual', 'cuáles', 'cuales', 'quién', 'quien', 'quiénes', 'quienes',
        'por qué', 'porque', 'para qué', 'para que',
    ];

    private const INFORMATIONAL_PHRASES = [
        'quiero saber', 'quisiera saber', 'necesito saber', 'me gustaría saber', 'me gustaria saber',
        'información sobre', 'informacion sobre', 'info sobre',
        'tienen', 'manejan', 'aceptan', 'cuentan con',
    ];

    private const TOPIC_KEYWORDS = [
        'horario', 'horarios', 'ubicación', 'ubicacion', 'dirección', 'direccion',
        'precio', 'precios', 'costo', 'costos', 'cuesta', 'vale',
        'política', 'politica', 'políticas', 'politicas',
        'cancelación', 'cancelacion', 'requisito', 'requisitos',
        'promoción', 'promocion', 'descuento', 'descuentos',
    ];

    public function looksLikeFaqQuestion(string $body): bool
    {
        $normalized = mb_strtolower(trim($body));

        if ($normalized === '') {
            return false;
        }

        if (str_contains($body, '¿') || str_ends_with(trim($body), '?')) {
            return true;
        }

        foreach ([...self::INTERROGATIVE_WORDS, ...self::INFORMATIONAL_PHRASES, ...self::TOPIC_KEYWORDS] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
