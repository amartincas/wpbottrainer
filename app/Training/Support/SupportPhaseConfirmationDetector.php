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
 * señal de negación, pregunta, dolor/molestia, petición de cambio, una
 * conjunción adversativa ("pero"), una referencia explícita a OTRO
 * ejercicio, o una construcción donde "hice"/"hecho" gobierna un objeto no
 * relacionado con la ejecución (pregunta/video/foto), se rechaza de
 * inmediato — sin importar qué palabra de confirmación contenga en otra
 * parte. Esto es lo que distingue este diseño de una búsqueda de subcadena
 * ciega: "listo pero me duele la rodilla" y "listo para empezar, pero antes
 * tengo una duda" nunca pasan, aunque empiecen con una palabra de
 * confirmación real.
 *
 * ETAPA 2 — CONFIRMACIÓN (solo si la Etapa 1 no rechazó):
 * (a) coincidencia EXACTA contra el vocabulario cerrado (`CONFIRMATION_PHRASES`); o
 * (b) el mensaje, normalizado (puntuación fuera), EMPIEZA CON una de un
 *     subconjunto acotado de frases de confirmación distintivas/inequívocas
 *     (`PREFIX_PATTERN`), seguida de un límite de palabra — nunca en medio
 *     de la frase; o
 * (c) Hito de confirmación en lenguaje natural (revisión de diseño v4) —
 *     UNA de esas mismas frases distintivas aparece en CUALQUIER posición
 *     del mensaje (no solo al inicio) **y**, además, el nombre del Support
 *     actual (`$exerciseName`, provisto por el llamador) también aparece —
 *     ambos con límites Unicode-aware, mismo patrón EXACTO que
 *     `RequestedFocusTermMapper::containsRecognizedTerm()`. Cubre "Rodillas
 *     altas, hice una serie x 90 segundos" / "Rodillas altas listo" /
 *     "Rodillas altas, ya terminé" — donde el usuario nombra primero el
 *     ejercicio y confirma después — sin aceptar una mención sola del
 *     ejercicio (la palabra de confirmación sigue siendo obligatoria en las
 *     tres vías, sin excepción).
 *
 * Justificación arquitectónica de por qué (b)/(c) NUNCA necesitan resolver
 * "cuál ejercicio" — solo "hubo confirmación, sí o no": la identidad del
 * ejercicio a avanzar NUNCA sale de este detector. `TrainingHandler` ya la
 * resolvió, de forma 100% determinista, vía
 * `WorkoutSession::frontExercise()`/`workout_exercise_id` — invariante de
 * entrega progresiva: solo puede existir UN Support "entregado y sin
 * resolver" a la vez. Por eso una confirmación GENÉRICA sin nombre (ej.
 * "Hice una serie x 90 segundos") es segura de aceptar por la vía (b): no
 * hay ningún otro candidato posible al que pudiera referirse.
 *
 * Palabras cortas y genéricas ("sí"/"si"/"ok"/"okay"/"ya"/"vamos"/"dale"/
 * "sigue"/"continuar"/"siguiente"/etc.) se mantienen EXACT-ONLY (vía a) —
 * deliberadamente NUNCA se habilitan como prefijo (b) ni como co-ocurrencia
 * (c), porque son demasiado frecuentes en frases no confirmatorias ("ya no
 * quiero", "sigue doliendo") para usarlas fuera de la coincidencia exacta
 * completa sin riesgo real de falso positivo.
 */
class SupportPhaseConfirmationDetector
{
    /**
     * Subconjunto DISTINTIVO — lo bastante inequívoco como para aceptarse
     * también como PREFIJO (vía b, `PREFIX_PATTERN`) y, si se provee el
     * nombre del ejercicio, en cualquier posición co-ocurriendo con él (vía
     * c, `DISTINCTIVE_PHRASE_PATTERN`). Las palabras genéricas/cortas de
     * `CONFIRMATION_PHRASES` (sí/si/ok/okay/ya/vamos/dale/sigue/...) NUNCA
     * entran aquí — ver docblock de la clase.
     *
     * Hito de confirmación en lenguaje natural (revisión v4) — agrega
     * `lista` (femenino de "listo"), `hecha` (femenino de "hecho"),
     * `terminado`/`terminada` (participio, misma concordancia de género que
     * "lista"), `hice`/`ya hice` (pretérito 1ª persona sin el pronombre
     * "lo") — vacíos de vocabulario reales encontrados en un E2E real de
     * staging, nunca ampliaciones especulativas.
     */
    private const DISTINCTIVE_PHRASES = [
        'listo', 'lista', 'hecho', 'hecha',
        'terminé', 'termine', 'terminado', 'terminada',
        'he terminado',
        'lo hice', 'lo hice ya', 'hice',
        'ya terminé', 'ya termine', 'ya lo hice', 'ya hice',
    ];

    private const CONFIRMATION_PHRASES = [
        'sí', 'si', 'ok', 'okay', 'ya',
        'continuar', 'siguiente', 'vamos', 'dale', 'sigamos', 'seguimos',
        'sigue', 'sigue adelante', 'vamos con el siguiente', 'ya está', 'ya esta',
        ...self::DISTINCTIVE_PHRASES,
    ];

    /**
     * Vía (b) — mismo conjunto que `DISTINCTIVE_PHRASES`, anclado al inicio
     * del mensaje normalizado, seguido de límite de palabra.
     */
    private const PREFIX_PATTERN = '/^(listo|lista|hecho|hecha|termin[eé]|terminad[oa]|he terminado|lo hice( ya)?|ya termin[eé]|ya lo hice|ya hice|hice)(\s|$)/u';

    /**
     * Vía (c) — mismo conjunto que `DISTINCTIVE_PHRASES`, en CUALQUIER
     * posición del mensaje (límites Unicode-aware, nunca `\b` de PCRE puro
     * — mismo criterio que `RequestedFocusTermMapper::containsRecognizedTerm()`
     * para reconocer tildes/ñ correctamente en los extremos de la
     * coincidencia). Solo se evalúa cuando el llamador proveyó
     * `$exerciseName` — ver `isExplicitConfirmation()`.
     */
    private const DISTINCTIVE_PHRASE_PATTERN = '/(?<![\p{L}\p{N}])(listo|lista|hecho|hecha|termin[eé]|terminad[oa]|he\s+terminado|lo\s+hice(\s+ya)?|ya\s+termin[eé]|ya\s+lo\s+hice|ya\s+hice|hice)(?![\p{L}\p{N}])/u';

    /**
     * Señales de rechazo — negación, pregunta, dolor/molestia, petición de
     * cambio. Se evalúan sobre el mensaje SIN quitar puntuación (para
     * poder detectar `?`/`¿`). Ganan siempre, sin importar qué palabra de
     * confirmación aparezca en cualquier otra parte del mensaje.
     */
    private const REJECT_STEMS = ['duele', 'duelen', 'dolor', 'molesta', 'cambi'];

    private const REJECT_WORD_PATTERN = '/\b(no|todav[ií]a|cuando|c[oó]mo|pero)\b/u';

    /**
     * Hito de confirmación en lenguaje natural (revisión v4) — referencia
     * explícita a OTRO ejercicio, NUNCA una palabra aislada
     * ("otro"/"otra"/"anterior" solas son demasiado amplias: "hice otra
     * serie" es una confirmación legítima del Support actual). Solo
     * rechaza cuando esas palabras acompañan directamente a "ejercicio", o
     * la construcción elíptica "el anterior" (sin "ejercicio" explícito,
     * pero refiriéndose a él por posición gramatical — único caso sin
     * ancla de sustantivo, evidenciado en el caso real "Terminé el
     * anterior"). Cubre "ejercicio anterior", "ejercicio de antes", "otro
     * ejercicio", "anterior ejercicio", "el anterior".
     */
    private const OTHER_EXERCISE_REFERENCE_PATTERN = '/\b(ejercicio\s+(anterior|de\s+antes)|otro\s+ejercicio|anterior\s+ejercicio|el\s+anterior)\b/u';

    /**
     * Hito de confirmación en lenguaje natural (revisión v4) — rechaza
     * ÚNICAMENTE cuando "hice"/"hecho"/"hecha" gobierna DIRECTAMENTE
     * (inmediatamente seguido, con artículo opcional) a "pregunta"/
     * "video"/"foto" — nunca por la mera presencia de esas palabras en
     * cualquier parte del mensaje ("Hice el ejercicio, vi el video y
     * terminé" NO debe rechazarse: "hice" gobierna "el ejercicio", no "el
     * video", que aparece en una cláusula distinta gobernada por "vi").
     */
    private const NON_COMPLETION_OBJECT_PATTERN = '/\b(hice|hecho|hecha)\s+(el|la|un|una)?\s*(pregunta|video|foto)\b/u';

    /**
     * @param  ?string  $exerciseName  Hito de confirmación en lenguaje
     *         natural (revisión v4) — nombre del `WorkoutExercise` Support
     *         actual (`$frontExercise['name']`, ya resuelto por
     *         `TrainingHandler` vía `WorkoutSession::frontExercise()`,
     *         única fuente de identidad — ver docblock de la clase). `null`
     *         (default) preserva EXACTAMENTE el comportamiento anterior a
     *         esta revisión para cualquier llamador que no lo provea: solo
     *         desactiva la vía (c), las vías (a)/(b) no dependen de él.
     */
    public function isExplicitConfirmation(string $body, ?string $exerciseName = null): bool
    {
        $lower = mb_strtolower(trim($body));

        if ($this->isRejected($lower)) {
            return false;
        }

        $normalized = mb_strtolower(trim(preg_replace('/[.!¡¿?,;]/u', '', $body) ?? $body));

        if (in_array($normalized, self::CONFIRMATION_PHRASES, true)) {
            return true;
        }

        if (preg_match(self::PREFIX_PATTERN, $normalized) === 1) {
            return true;
        }

        if ($exerciseName !== null
            && preg_match(self::DISTINCTIVE_PHRASE_PATTERN, $normalized) === 1
            && $this->containsExerciseName($normalized, $exerciseName)) {
            return true;
        }

        return false;
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

        if (preg_match(self::REJECT_WORD_PATTERN, $lower) === 1) {
            return true;
        }

        if (preg_match(self::OTHER_EXERCISE_REFERENCE_PATTERN, $lower) === 1) {
            return true;
        }

        return preg_match(self::NON_COMPLETION_OBJECT_PATTERN, $lower) === 1;
    }

    /**
     * Hito de confirmación en lenguaje natural (revisión v4) — mismo patrón
     * EXACTO de límites Unicode-aware que
     * `RequestedFocusTermMapper::containsRecognizedTerm()`. El nombre del
     * ejercicio se compara ÚNICAMENTE contra `$exerciseName` (el Support
     * actual, ya resuelto por el llamador) — nunca contra ningún otro
     * ejercicio del catálogo, nunca con fuzzy matching, nunca con stripping
     * de acentos (mismo nivel de normalización que `containsRecognizedTerm()`:
     * solo minúsculas).
     */
    private function containsExerciseName(string $normalized, string $exerciseName): bool
    {
        $needle = mb_strtolower(trim($exerciseName));

        if ($needle === '') {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';

        return preg_match($pattern, $normalized) === 1;
    }
}
