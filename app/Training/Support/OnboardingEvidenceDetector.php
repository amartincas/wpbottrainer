<?php

namespace App\Training\Support;

use App\Models\TrainingProfile;
use App\Training\Enums\TrainingLocation;

/**
 * Hito O2 (Onboarding sticky state / deterministic backstop, diseño formal
 * aprobado, Fase 1 — EXCLUSIVAMENTE `training_location`) — backstop
 * determinista para el único caso real reproducido: el usuario menciona
 * explícitamente dónde entrena, pero el LLM no lo extrae ese turno, y el
 * sistema vuelve a preguntar algo ya respondido. Mismo espíritu exacto que
 * `SafetySignalDetector`/`RequestedFocusTermMapper`/`BodyRegionCanonicalMapper`:
 * 100% determinista, sin IA, sin DB, sin `Contact` — correspondencia
 * estructural sobre un vocabulario cerrado, NUNCA aproximación por palabra
 * clave suelta, fuzzy matching, stemming, sinónimos automáticos ni
 * embeddings.
 *
 * NUNCA sustituye al LLM — es una red de seguridad complementaria para el
 * subconjunto de vocabulario cerrado y sintácticamente simple
 * (`home`/`gym`/`outdoor`, 3 valores). El prompt combinado
 * (`OnboardingConversationService`) sigue siendo la vía primaria de
 * extracción; esta clase nunca se invoca en su lugar, solo complementa
 * huecos que el LLM dejó sin llenar (ver `fillGaps()`).
 *
 * DELIBERADAMENTE fuera de esta Fase 1 (decisión de diseño aprobada, no un
 * olvido): `available_equipment`, `goal`, `experience_level`,
 * `sessions_per_week`, `split_type`, `primary_focus`, `secondary_focus`.
 * Ninguno de esos campos tiene backstop determinista todavía.
 *
 * Aislamiento de Safety (diseño aprobado, Sección 4/8) — esta clase NUNCA
 * importa ni conoce `HealthConditionCategory`, `restrictions`,
 * `safety_signal_text`, `TrainingAccess`, ni `SafetyRestrictionResolver`.
 * `TrainingHandler` es quien garantiza que `fillGaps()` solo se invoque
 * DESPUÉS de que el chequeo de seguridad ya decidió no escalar — esta clase
 * no necesita (ni debe) saber nada de esa decisión.
 */
class OnboardingEvidenceDetector
{
    /**
     * Patrón estructural único: ANCLA_DE_ENTRENAMIENTO -> PREPOSICIÓN ->
     * UBICACIÓN, sin ninguna palabra de relleno entre la ancla y la
     * preposición (diseño aprobado, Sección 3 — nunca una ventana genérica
     * de N palabras). El texto ya llega normalizado (minúsculas, sin
     * tildes) por `normalize()` antes de aplicarse este patrón.
     *
     * Grupo 1 — ancla capturada (`entreno`/`entrenar`/`practico`/
     * `hago ejercicio`/`voy`). "entrenar" como palabra suelta ya cubre
     * cualquier prefijo libre delante ("quiero entrenar", "voy a entrenar",
     * "suelo entrenar", "para entrenar") — solo importa que la palabra
     * final antes de la preposición sea exactamente "entrenar": no hace
     * falta enumerar cada prefijo por separado.
     *
     * Grupo 2 — ubicación capturada. "casa" lleva un lookahead negativo
     * `(?!\s+de\b)` — excluye "casa de mi amigo/hermano/etc.", que no es
     * sinónimo confiable del hogar habitual del usuario. "aire libre" es la
     * ÚNICA frase válida para `outdoor` — "afuera" en solitario queda
     * deliberadamente fuera del vocabulario (demasiado genérico: "salí
     * afuera", "está afuera", sin relación con entrenamiento).
     *
     * Guard de negación — `(?<!\bno\s)(?<!\bnunca\s)(?<!\bjamas\s)`: si
     * "no"/"nunca"/"jamás" precede INMEDIATAMENTE a la ancla (una sola
     * palabra, un solo espacio de separación), esa ocurrencia queda
     * invalidada por completo — nunca se infiere el valor opuesto (ver
     * `detectTrainingLocation()`). Lookbehind de ancho fijo (requisito de
     * PCRE) — cubre exactamente el caso exigido por el corpus aprobado
     * ("No entreno en gimnasio..."), no variantes con puntuación o espacios
     * múltiples entre la negación y la ancla (limitación conocida y
     * aceptada de esta Fase 1).
     *
     * La preposición NUNCA acepta "a" suelta (solo "en"/"al"/"a la") — esto
     * es lo que descarta correctamente "voy a mi casa a descansar" y "voy a
     * comprar mancuernas..." sin necesitar ninguna regla adicional: "a" sola
     * nunca satisface el grupo de preposición.
     */
    private const TRAINING_LOCATION_PATTERN =
        '/(?<!\bno\s)(?<!\bnunca\s)(?<!\bjamas\s)\b(entreno|entrenar|practico|hago ejercicio|voy)\s+(?:en|al|a la)\s+(?:el\s+)?(gimnasio|gym|casa(?!\s+de\b)|aire\s+libre)\b/u';

    /**
     * Único punto de integración con el flujo de onboarding — ver
     * `TrainingHandler`, invocado DESPUÉS del chequeo de
     * `safety_signal_text` (nunca antes) y ANTES de
     * `OnboardingRequirementRegistry::applyExtracted()`.
     *
     * Precedencia obligatoria (diseño aprobado, Sección 2) — LLM > Profile >
     * Backstop, aplicada aquí de forma literal y sin excepciones:
     * - Si `$extracted['training_location']` ya es no-null (el LLM SÍ lo
     *   extrajo este turno), el detector NUNCA se ejecuta — el LLM gana
     *   siempre, sin importar qué habría detectado el patrón.
     * - Si `$profile->training_location` ya tiene un valor persistido, el
     *   detector NUNCA se ejecuta — nunca sobrescribe un valor existente
     *   (ni siquiera con el mismo valor). Esto es lo que hace que el
     *   ejemplo adversarial "perfil ya en gym, usuario menciona gimnasio en
     *   otro contexto" sea inofensivo por construcción, sin necesitar
     *   ninguna heurística de "declaración vs. contexto": el detector ni
     *   se plantea actuar.
     * - Solo cuando AMBOS son `null` se invoca `detectTrainingLocation()`.
     *
     * Esta fase NO implementa detección de correcciones: un perfil con
     * `training_location` ya persistido solo puede cambiar vía el LLM (como
     * ya ocurre hoy) — ver diseño aprobado, Sección 2, punto E.
     *
     * @param  array<string, mixed>  $extracted  el array "extracted" ya
     *         devuelto (y ya validado) por
     *         `OnboardingConversationService::extractAndRespond()`.
     * @return array<string, mixed> el mismo array, con `training_location`
     *         completado únicamente si correspondía (nunca ninguna otra
     *         clave se toca en esta Fase 1).
     */
    public function fillGaps(array $extracted, string $body, TrainingProfile $profile): array
    {
        if (($extracted['training_location'] ?? null) !== null) {
            return $extracted;
        }

        if ($profile->training_location !== null) {
            return $extracted;
        }

        $detected = $this->detectTrainingLocation($body);

        // Revalidación defensiva contra el mismo vocabulario cerrado que ya
        // usa el camino del LLM (TrainingLocation::tryFrom()) — nunca se
        // confía ciegamente en la propia salida de este detector, mismo
        // criterio que el resto del proyecto aplica a cualquier fuente,
        // incluida la suya propia.
        if ($detected !== null && TrainingLocation::tryFrom($detected) !== null) {
            $extracted['training_location'] = $detected;
        }

        return $extracted;
    }

    /**
     * Detección pura, 100% determinista: recibe únicamente el texto crudo
     * del mensaje — nunca `Contact`/`TrainingProfile`/estado alguno. Ver
     * docblock de `TRAINING_LOCATION_PATTERN` para la especificación
     * completa del patrón y sus guards.
     *
     * Ante MÚLTIPLES coincidencias válidas y distintas en el mismo mensaje
     * (ej. "antes entrenaba en el gimnasio pero ahora entreno en casa"), se
     * usa la ÚLTIMA — mismo criterio de "lo más reciente en el propio
     * mensaje es lo vigente" (diseño aprobado, Sección B).
     *
     * @return ?string uno de `TrainingLocation::cases()[]->value` (`home`,
     *         `gym`, `outdoor`), o `null` si no hay ninguna declaración
     *         inequívoca.
     */
    public function detectTrainingLocation(string $body): ?string
    {
        $normalized = $this->normalize($body);

        if (preg_match_all(self::TRAINING_LOCATION_PATTERN, $normalized, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        $resolved = null;

        foreach ($matches[1] as $index => [$anchor]) {
            $locationToken = $matches[2][$index][0];
            $value = $this->resolveLocationValue($locationToken);

            if ($anchor === 'voy' && $value !== TrainingLocation::Gym->value) {
                // Regla especial aprobada: "voy" es ancla válida
                // ÚNICAMENTE para gym ("voy al gimnasio"/"voy al gym") —
                // nunca para "casa"/"aire libre" ("voy a mi casa a
                // descansar" no es una declaración de entrenamiento).
                continue;
            }

            $resolved = $value;
        }

        return $resolved;
    }

    private function resolveLocationValue(string $token): string
    {
        return match (true) {
            $token === 'gimnasio' || $token === 'gym' => TrainingLocation::Gym->value,
            $token === 'casa' => TrainingLocation::Home->value,
            default => TrainingLocation::Outdoor->value, // "aire libre" (con espaciado normalizado)
        };
    }

    /**
     * Minúsculas + sin tildes — mismo criterio de normalización ya usado
     * por `RequestedFocusTermMapper::normalize()`/
     * `TrainingPreferenceMessageClassifier::normalize()` (reescrito aquí,
     * no importado desde ahí: esta clase no conoce nada de B1/B3, mismo
     * principio de aislamiento por dominio ya aplicado en todo el
     * proyecto).
     */
    private function normalize(string $text): string
    {
        $lower = mb_strtolower(trim($text));

        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];

        return strtr($lower, $map);
    }
}
