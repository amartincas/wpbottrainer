<?php

namespace App\CustomerCare\Support;

/**
 * Hito 14 — detección determinista (sin IA) de una petición EXPLÍCITA de
 * atención humana — mismo patrón que `App\Training\Support\
 * SafetySignalDetector`: una única fuente de verdad reutilizada tanto por
 * el clasificador de Core (`CustomerServiceEscalationIntentClassifier`,
 * camino independiente) como por el propio Handler (`CustomerCareHandler`)
 * — evita el tipo de bug ya encontrado en Hito 13 (clasificador y
 * consumidor con listas de palabras que divergen).
 *
 * Vocabulario CERRADO — mismos ejemplos del diseño original. No incluye
 * palabras genéricas de dominio (ej. "pago") para no colisionar con
 * Payment/Training — ver docs/DECISIONS.md (hallazgo de "Tengo un problema
 * con el pago").
 */
class CustomerServiceEscalationDetector
{
    private const PHRASES = [
        'hablar con alguien', 'hablar con una persona', 'hablar con un humano',
        'necesito atención', 'necesito atencion',
        'atención humana', 'atencion humana',
        'tengo un problema', 'tengo un inconveniente',
        'no puedo continuar', 'no puedo seguir',
        'necesito ayuda', 'necesito ayuda con',
        'quiero hablar con', 'quiero que me ayuden',
        'necesito hablar con', 'necesito que me ayuden',
        'esto no funciona', 'no me funciona', 'no está funcionando', 'no esta funcionando',
    ];

    public function detect(string $body): bool
    {
        $normalized = mb_strtolower($body);

        foreach (self::PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
