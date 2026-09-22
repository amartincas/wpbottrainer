<?php

namespace App\Training\Support;

/**
 * Hito R1/R2/R3, corrección post-incidente de staging (#33) — reconoce, de
 * forma 100% determinista y sin IA, si un mensaje es una confirmación
 * explícita del usuario para avanzar más allá de un ejercicio de
 * preparación/cooldown (que no piden reporte estructurado — ver
 * `WorkoutExercise::requiresExecutionReport()`).
 *
 * Deliberadamente NO reutiliza `TrainingHandler::isShortAffirmativeAfterReminder()`
 * (efecto secundario real sobre `Reminder`, vocabulario distinto — ver
 * docs/DECISIONS.md de este hito). Reutiliza únicamente el MISMO IDIOMA de
 * normalización ya establecido en ese método (trim + minúsculas + quitar
 * puntuación).
 *
 * Diseño de 2 etapas (nunca `str_contains($body, 'listo')` ingenuo):
 *
 * ETAPA 1 — RECHAZO (se evalúa PRIMERO, sobre el mensaje con puntuación
 * intacta, para poder detectar `?`/`¿`): si el mensaje contiene cualquier
 * señal de negación, pregunta, dolor/molestia, petición de cambio, o una
 * conjunción adversativa ("pero") que introduce una salvedad, se rechaza
 * de inmediato — sin importar qué palabra de confirmación contenga en
 * otra parte. Esto es lo que distingue este diseño de una búsqueda de
 * subcadena ciega: "listo pero me duele la rodilla" y "listo para
 * empezar, pero antes tengo una duda" nunca pasan, aunque empiecen con
 * una palabra de confirmación real.
 *
 * ETAPA 2 — CONFIRMACIÓN (solo si la Etapa 1 no rechazó):
 * (a) coincidencia EXACTA contra el vocabulario cerrado histórico (sin
 *     cambios de comportamiento para estas 24 frases); o
 * (b) el mensaje, normalizado (puntuación fuera), EMPIEZA CON una de un
 *     subconjunto acotado de frases de confirmación inequívocas
 *     ("listo", "hecho", "terminé"/"termine", "he terminado", "lo hice"/
 *     "lo hice ya", "ya terminé", "ya lo hice"), seguida de un límite de
 *     palabra — nunca en medio de la frase. Cubre el caso real del
 *     incidente ("listo rodillas altas", "listo, una serie de 90
 *     segundos") sin aceptar mensajes libres.
 *
 * Palabras cortas y genéricas ("sí"/"si"/"ok"/"okay"/"ya"/"vamos"/"dale"/
 * "sigue"/"continuar"/"siguiente"/etc.) se mantienen EXACT-ONLY —
 * deliberadamente NO se habilitan como prefijo, porque son demasiado
 * frecuentes en frases no confirmatorias ("ya no quiero", "sigue
 * doliendo") para usarlas como inicio de coincidencia parcial sin riesgo
 * real de falso positivo.
 */
class SupportPhaseConfirmationDetector
{
    private const CONFIRMATION_PHRASES = [
        'sí', 'si', 'listo', 'hecho', 'ok', 'okay', 'ya', 'terminé', 'termine',
        'continuar', 'siguiente', 'vamos', 'dale', 'sigamos', 'seguimos',
        'sigue', 'sigue adelante', 'vamos con el siguiente', 'ya está', 'ya esta',
        'he terminado', 'lo hice', 'lo hice ya',
        // Corrección post-incidente #33 — variantes explícitas pedidas.
        'ya terminé', 'ya termine', 'ya lo hice',
    ];

    /**
     * Subconjunto de CONFIRMATION_PHRASES lo bastante distintivo/inequívoco
     * como para aceptarse también como PREFIJO (seguido de contexto
     * adicional del usuario) — nunca las palabras cortas/genéricas de
     * arriba.
     */
    private const PREFIX_PATTERN = '/^(listo|hecho|termin[eé]|he terminado|lo hice( ya)?|ya termin[eé]|ya lo hice)(\s|$)/u';

    /**
     * Señales de rechazo — negación, pregunta, dolor/molestia, petición de
     * cambio. Se evalúan sobre el mensaje SIN quitar puntuación (para
     * poder detectar `?`/`¿`). Ganan siempre, sin importar qué palabra de
     * confirmación aparezca en cualquier otra parte del mensaje.
     */
    private const REJECT_STEMS = ['duele', 'duelen', 'dolor', 'molesta', 'cambi'];

    private const REJECT_WORD_PATTERN = '/\b(no|todav[ií]a|cuando|c[oó]mo|pero)\b/u';

    public function isExplicitConfirmation(string $body): bool
    {
        $lower = mb_strtolower(trim($body));

        if ($this->isRejected($lower)) {
            return false;
        }

        $normalized = mb_strtolower(trim(preg_replace('/[.!¡¿?,;]/u', '', $body) ?? $body));

        if (in_array($normalized, self::CONFIRMATION_PHRASES, true)) {
            return true;
        }

        return (bool) preg_match(self::PREFIX_PATTERN, $normalized);
    }

    private function isRejected(string $lower): bool
    {
        if (str_contains($lower, '?') || str_contains($lower, '¿')) {
            return true;
        }

        foreach (self::REJECT_STEMS as $stem) {
            if (str_contains($lower, $stem)) {
                return true;
            }
        }

        return (bool) preg_match(self::REJECT_WORD_PATTERN, $lower);
    }
}
