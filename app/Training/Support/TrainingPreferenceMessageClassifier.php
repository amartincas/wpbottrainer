<?php

namespace App\Training\Support;

use App\Training\Enums\PreferenceMessageCategory;

/**
 * Hito B3 (Preferencias persistentes, diseño v3 FINAL) — gramática semántica
 * mínima, 100% determinista (regex, sin IA), mismo espíritu exacto que
 * `SupportPhaseConfirmationDetector` (v4): vocabulario cerrado y curado,
 * nunca un clasificador de lenguaje natural genérico. Opera SIEMPRE sobre
 * `$body` crudo, ANTES de cualquier llamada de IA — el LLM nunca decide si
 * un mensaje "es" una Preference (Regla 8 del encargo).
 *
 * Precedencia FIJA (diseño v3, Sección A.2), nunca por descarte:
 * Safety > Temporal > Permanence > InstanceAnchor > Dislike > ActionRefusal
 * > Ambiguous > None.
 *
 * `null` (None) significa "ningún marcador coincidió" — el turno continúa
 * exactamente como si B3 no existiera. `Ambiguous` significa "se detectó
 * algo relevante del dominio (un rechazo de acción sin contexto, o un 'no
 * puedo' desnudo), pero no se puede resolver sin preguntar" — nunca se
 * adivina (Regla 15 del encargo).
 *
 * Todas las comparaciones son case-insensitive y sin acentos (mismo criterio
 * de robustez que `RequestedFocusTermMapper::containsRecognizedTerm()`,
 * aunque aquí SÍ se normalizan acentos porque el vocabulario cerrado de esta
 * clase es más amplio y variable que el de B1) — nunca fuzzy/substring sin
 * límites de palabra donde eso importa (ver límites Unicode abajo).
 */
class TrainingPreferenceMessageClassifier
{
    /**
     * Reutiliza CONCEPTUALMENTE el mismo vocabulario ya validado por
     * `SafetySignalDetector` para dolor/lesión — NUNCA se amplía el
     * vocabulario de EMERGENCIA (`chest_pain`/`breathing_difficulty`/etc.,
     * que sigue siendo responsabilidad exclusiva de ese detector, sin
     * tocar). Estas dos listas son un vocabulario SEPARADO y de menor
     * severidad — declaraciones cotidianas de dolor/lesión/cirugía dentro de
     * una conversación normal, enrutadas a `DeclaredHealthConditionRecorder`
     * (pending_review, nunca pausa el entrenamiento — ver
     * `TrainingHandler::handleTrainingPreferenceMessage()`).
     */
    private const SAFETY_INJURY_MARKERS = [
        'me duele', 'me duelen', 'me lastime', 'lesion', 'me hice dano',
    ];

    private const SAFETY_RECOVERY_MARKERS = [
        'me operaron', 'operacion', 'cirugia', 'recien operado', 'recien operada',
    ];

    /**
     * Regla de co-ocurrencia (diseño v3, Sección A.2): un calificador
     * temporal SOLO importa combinado con un rechazo ("no quiero") — "hoy
     * hice mi rutina" (sin "no quiero") nunca debe activar esta categoría.
     */
    private const TEMPORAL_MARKERS = ['hoy', 'por ahora', 'esta semana', 'ahorita', 'ahora mismo'];

    private const TEMPORAL_FATIGUE_TRIGGERS = ['cansado', 'cansada', 'cansancio', 'prisa'];

    private const PERMANENCE_MARKERS = [
        'no quiero volver a', 'no vuelvo a', 'prefiero no volver a',
        'ya no quiero', 'nunca mas', 'de ahora en adelante',
    ];

    /**
     * Revisión crítica v3 — demostrativos/pronombres anafóricos que anclan
     * una instancia puntual ("esta"/"esa"/"la"/"lo" como objeto directo del
     * rechazo). NUNCA genera una Preference, incluso combinado con
     * `DISLIKE_MARKERS` (ver precedencia — se comprueba ANTES que Dislike).
     */
    private const INSTANCE_ANCHOR_MARKERS = [
        'no quiero hacerla', 'no quiero hacerlo',
        'no quiero hacer esta', 'no quiero hacer esa', 'no quiero hacer este ejercicio',
        'esta no la quiero hacer', 'esa no la quiero hacer', 'este no lo quiero hacer',
        // Revisión crítica v3, Sección 2 ("Prefiero no hacer esta") — el
        // mismo ancla de instancia también debe ganar cuando se combina con
        // la forma "prefiero no", no solo "no quiero".
        'prefiero no hacerla', 'prefiero no hacerlo',
        'prefiero no hacer esta', 'prefiero no hacer esa', 'prefiero no hacer este ejercicio',
    ];

    /**
     * Revisión crítica v3, punto 2 — ampliado respecto al diseño original:
     * "no soy fan de"/"no es lo mío" son semánticamente idénticos a "no me
     * gusta(n)" (estado general, nunca instancia) pero no estaban cubiertos.
     */
    private const DISLIKE_MARKERS = [
        'no me gusta', 'no me gustan', 'prefiero no',
        'no soy fan de', 'no es lo mio', 'no son lo mio',
    ];

    /**
     * "No tengo X" es Availability (`TrainingProfile.available_equipment`),
     * NUNCA Preference — B3 no la toca (Regla 12 del encargo). Su única
     * función aquí es DESACTIVAR la ambigüedad de "no puedo" (Sección A.3):
     * "no puedo usar mancuernas porque no tengo" nunca debe generar ninguna
     * acción de B3.
     */
    private const AVAILABILITY_MARKER = 'no tengo';

    private const ACTION_REFUSAL_MARKERS = ['no quiero hacer', 'no quiero usar', 'no quiero'];

    private const NO_PUEDO_MARKERS = ['no puedo hacer', 'no puedo usar', 'no puedo'];

    public function classify(string $body): TrainingPreferenceClassification
    {
        $normalized = $this->normalize($body);

        if ($normalized === '') {
            return TrainingPreferenceClassification::none();
        }

        // 1. Safety — máxima precedencia, sin excepción (Regla 11: nunca se
        // mezcla con Preference, sin importar qué otro marcador coexista).
        if ($this->containsAny($normalized, self::SAFETY_INJURY_MARKERS)) {
            return new TrainingPreferenceClassification(PreferenceMessageCategory::Safety, safetySubcategory: 'injury');
        }

        if ($this->containsAny($normalized, self::SAFETY_RECOVERY_MARKERS)) {
            return new TrainingPreferenceClassification(PreferenceMessageCategory::Safety, safetySubcategory: 'recovery');
        }

        // 2. Temporal — bloquea persistencia SIEMPRE, sin importar qué otro
        // marcador de "querer" coexista (diseño v3, Sección 3, Opción A).
        $temporalByQualifier = $this->containsAny($normalized, self::TEMPORAL_MARKERS)
            && str_contains($normalized, 'no quiero');
        $temporalByFatigue = str_contains($normalized, 'porque')
            && $this->containsAny($normalized, self::TEMPORAL_FATIGUE_TRIGGERS);

        if ($temporalByQualifier || $temporalByFatigue) {
            return new TrainingPreferenceClassification(PreferenceMessageCategory::Temporal);
        }

        // 3. Permanence.
        $permanenceMarker = $this->firstMatch($normalized, self::PERMANENCE_MARKERS);

        if ($permanenceMarker !== null) {
            return new TrainingPreferenceClassification(
                PreferenceMessageCategory::Permanence,
                candidateTerm: $this->extractCandidateTerm($normalized, $permanenceMarker),
            );
        }

        // 4. InstanceAnchor — ANTES que Dislike a propósito (revisión crítica
        // v3, Sección 2: "Prefiero no hacer esta" debe ganar como instancia
        // puntual, no como Preference general).
        if ($this->containsAny($normalized, self::INSTANCE_ANCHOR_MARKERS)) {
            return new TrainingPreferenceClassification(PreferenceMessageCategory::InstanceAnchor);
        }

        // 5. Dislike.
        $dislikeMarker = $this->firstMatch($normalized, self::DISLIKE_MARKERS);

        if ($dislikeMarker !== null) {
            return new TrainingPreferenceClassification(
                PreferenceMessageCategory::Dislike,
                candidateTerm: $this->extractCandidateTerm($normalized, $dislikeMarker),
            );
        }

        // 6. Availability — "no tengo" nunca produce ninguna acción de B3
        // (Sección A.3/A.7): se comprueba DESPUÉS de Safety/Dislike (una
        // declaración de dolor o de disgusto sigue ganando si coexiste) pero
        // ANTES de "no puedo" desnudo, precisamente para desactivarlo aquí.
        if (str_contains($normalized, self::AVAILABILITY_MARKER)) {
            return TrainingPreferenceClassification::none();
        }

        // 7. "No puedo" desnudo (Sección A.3) — Ambiguo, nunca se asume
        // Safety ni Preference sin el marcador correspondiente.
        $noPuedoMarker = $this->firstMatch($normalized, self::NO_PUEDO_MARKERS);

        if ($noPuedoMarker !== null) {
            return new TrainingPreferenceClassification(
                PreferenceMessageCategory::Ambiguous,
                candidateTerm: $this->extractCandidateTerm($normalized, $noPuedoMarker),
                noPuedoBare: true,
            );
        }

        // 8. ActionRefusal desnudo — la resolución final (report vs.
        // ambiguo) depende del contexto de Main pendiente, que este
        // clasificador NUNCA conoce (Regla 9 del encargo) — la decide
        // exclusivamente el llamador (TrainingHandler).
        $actionRefusalMarker = $this->firstMatch($normalized, self::ACTION_REFUSAL_MARKERS);

        if ($actionRefusalMarker !== null) {
            return new TrainingPreferenceClassification(
                PreferenceMessageCategory::ActionRefusal,
                candidateTerm: $this->extractCandidateTerm($normalized, $actionRefusalMarker),
            );
        }

        return TrainingPreferenceClassification::none();
    }

    /**
     * lowercase + sin acentos/diacríticos — únicamente para efectos de
     * comparación contra el vocabulario cerrado de esta clase. El texto
     * candidato resultante (`extractCandidateTerm()`) también queda en este
     * mismo espacio normalizado — `TrainingPreferenceIdentityResolver`
     * aplica su propia normalización (Sección A.4) de todos modos, así que
     * no hace falta preservar mayúsculas/acentos originales aquí.
     */
    private function normalize(string $text): string
    {
        $lower = mb_strtolower(trim($text));

        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];

        return strtr($lower, $map);
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Devuelve el marcador MÁS LARGO que coincide (para que, ante
     * superposición, ej. "no quiero" vs. "no quiero hacer", se prefiera
     * siempre el más específico al extraer el término candidato) — nunca el
     * primero en orden de declaración.
     */
    private function firstMatch(string $haystack, array $markers): ?string
    {
        $matches = array_filter($markers, fn (string $marker) => str_contains($haystack, $marker));

        if ($matches === []) {
            return null;
        }

        usort($matches, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $matches[0];
    }

    /**
     * Extrae el texto que sigue al marcador ya identificado, limpiando
     * conectores/artículos/puntuación — nunca stemming ni fuzzy matching
     * (Regla del encargo, Sección 6/A.4: la seguridad de identidad es más
     * importante que la comodidad). El resultado es solo un CANDIDATO — la
     * resolución de identidad real (única/ambigua/sin match) es
     * responsabilidad exclusiva de `TrainingPreferenceIdentityResolver`.
     */
    private function extractCandidateTerm(string $normalized, string $marker): ?string
    {
        $position = mb_strpos($normalized, $marker);

        if ($position === false) {
            return null;
        }

        $tail = mb_substr($normalized, $position + mb_strlen($marker));

        // Corta en "porque" — la cláusula causal no es parte del término.
        $tail = preg_split('/\bporque\b/u', $tail)[0] ?? $tail;

        // Conectores que el marcador puede no haber consumido todavía.
        $tail = preg_replace('/^\s*(volver a\s+)?(hacer|usar|utilizar)\b/u', '', $tail) ?? $tail;

        // Artículo inicial único.
        $tail = preg_replace('/^\s*(el|la|los|las|un|una)\b/u', '', $tail) ?? $tail;

        $tail = trim($tail, " \t\n\r\0\x0B.,;:!?¿¡");
        $tail = trim(preg_replace('/\s+/u', ' ', $tail) ?? $tail);

        return $tail === '' ? null : $tail;
    }
}
