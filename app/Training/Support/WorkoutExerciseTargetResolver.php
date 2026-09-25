<?php

namespace App\Training\Support;

use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;

/**
 * Hito C (Sustitución de un ejercicio, diseño formal aprobado, Fases 1/6) —
 * convierte lenguaje conversacional crudo en un `WorkoutExercise` objetivo
 * concreto. 100% determinista, sin IA, sin fuzzy matching, sin sinónimos,
 * sin stemming — mismo espíritu exacto que `TrainingPreferenceMessageClassifier`/
 * `TrainingPreferenceIdentityResolver` (B3), pero deliberadamente SIN
 * conocer nada de B3 ni de `Exercise::query()`: el universo de búsqueda es
 * EXCLUSIVAMENTE `$session->workoutExercises` (ya filtrado por
 * `superseded_by_id IS NULL`, ver `WorkoutSession::workoutExercises()`) —
 * nunca el catálogo completo.
 *
 * Cuatro vías, en este orden de especificidad (nunca cruzadas
 * arbitrariamente, nunca `first()`/`latest()` como desempate):
 * 1. Ordinal ("cámbiame el segundo") — vocabulario cerrado y curado de
 *    palabras ordinales, resuelto contra la posición dentro de `order`
 *    (global Preparation→Main→Cooldown, mismo orden ya usado por la
 *    entrega). Fuera de rango -> unresolved, NUNCA el último/primero real.
 * 2. Ancla EXPLÍCITA ("este ejercicio", "ese ejercicio", "cámbiame este",
 *    "no quiero este") — vocabulario cerrado ("este"/"ese", por palabra
 *    completa) resuelto directamente contra `WorkoutSession::frontExercise()`,
 *    SIN intentar la vía 3 (nombre) en absoluto. Fix pre-commit (auditoría
 *    Fase 2, hallazgo real): sin este paso, un mensaje como "cámbiame este
 *    ejercicio de pecho" podía resolver por NOMBRE contra otro
 *    `WorkoutExercise` de la MISMA sesión cuyo nombre contuviera
 *    incidentalmente la palabra de `requested_focus_terms` ("pecho"),
 *    desviando la sustitución hacia un ejercicio que el usuario nunca
 *    nombró — el foco es una restricción para el REEMPLAZO, nunca evidencia
 *    para decidir el OBJETIVO. Una ancla explícita dejar zanjada la
 *    identidad del objetivo por completo, antes de que cualquier palabra de
 *    foco pueda interferir. Sin frente entregado -> unresolved, NUNCA se
 *    asume el primero de la sesión.
 * 3. Nombre explícito ("cámbiame las sentadillas") — coincidencia de
 *    subcadena, case/acento-insensible, contra `exercise_snapshot['name']`
 *    de cada `WorkoutExercise` ACTIVO de la sesión (universo pequeño, nunca
 *    el catálogo). Único match -> resuelto; 2+ -> ambiguous; 0 -> se
 *    continúa a la vía 4. Solo se evalúa cuando la vía 2 NO detectó ancla
 *    explícita — evita exactamente la colisión descrita arriba.
 * 4. Ancla IMPLÍCITA (fallback final, ej. "quiero otro ejercicio", sin
 *    "este"/"ese" ni nombre ni ordinal) — mismo `frontExercise()` que la
 *    vía 2, como último recurso cuando ninguna de las anteriores resolvió
 *    nada. Sin frente entregado -> unresolved.
 */
class WorkoutExerciseTargetResolver
{
    /**
     * Vocabulario cerrado y curado — mismo criterio que
     * `SHORT_AFFIRMATIVE_WORDS`/vocabularios cerrados ya usados en el
     * proyecto. Claves ya normalizadas (sin acentos) — ver `normalize()`.
     */
    private const ORDINAL_WORDS = [
        'primero' => 1,
        'primer' => 1,
        'segundo' => 2,
        'tercero' => 3,
        'tercer' => 3,
        'cuarto' => 4,
        'quinto' => 5,
        'sexto' => 6,
        'septimo' => 7,
        'octavo' => 8,
    ];

    /**
     * Vocabulario cerrado de ANCLA EXPLÍCITA — fix pre-commit (auditoría
     * Fase 2). Palabras completas (nunca subcadena arbitraria): cubre "este
     * ejercicio"/"ese ejercicio"/"cámbiame este"/"no quiero este" sin
     * necesitar cada frase completa por separado, ya que todas contienen
     * "este"/"ese" como palabra propia.
     */
    private const ANCHOR_WORDS = ['este', 'ese'];

    public function resolve(WorkoutSession $session, string $body): WorkoutExerciseTargetResolution
    {
        $active = $session->workoutExercises;

        if ($active->isEmpty()) {
            return WorkoutExerciseTargetResolution::unresolved();
        }

        $normalizedBody = $this->normalize($body);

        $ordinalPosition = $this->extractOrdinal($normalizedBody);

        if ($ordinalPosition !== null) {
            $target = $active->values()->get($ordinalPosition - 1);

            return $target !== null
                ? WorkoutExerciseTargetResolution::resolved($target)
                : WorkoutExerciseTargetResolution::unresolved();
        }

        // Vía 2 — ancla EXPLÍCITA: gana ANTES de intentar resolución por
        // nombre (fix pre-commit, ver docblock de la clase) — nunca deja que
        // una palabra de `requested_focus_terms` en la misma frase ("...de
        // pecho") pueda desviar la identidad hacia otro WorkoutExercise de
        // la sesión que comparta esa palabra en su nombre.
        if ($this->hasExplicitAnchorMarker($normalizedBody)) {
            $front = $session->frontExercise();

            return $front !== null
                ? WorkoutExerciseTargetResolution::resolved($front)
                : WorkoutExerciseTargetResolution::unresolved();
        }

        $nameMatches = $active->filter(
            fn (WorkoutExercise $workoutExercise) => $this->nameMatchesBody($workoutExercise, $normalizedBody)
        )->values();

        if ($nameMatches->count() === 1) {
            return WorkoutExerciseTargetResolution::resolved($nameMatches->first());
        }

        if ($nameMatches->count() > 1) {
            return WorkoutExerciseTargetResolution::ambiguous($nameMatches->all());
        }

        // Vía 4 — ancla IMPLÍCITA (fallback final, ej. "quiero otro
        // ejercicio"): ni ordinal, ni ancla explícita, ni nombre matchearon.
        $front = $session->frontExercise();

        return $front !== null
            ? WorkoutExerciseTargetResolution::resolved($front)
            : WorkoutExerciseTargetResolution::unresolved();
    }

    /**
     * Fix pre-commit (auditoría Fase 2) — "este"/"ese" como PALABRA COMPLETA
     * (nunca subcadena arbitraria, nunca dentro de otra palabra) en
     * CUALQUIER parte del mensaje. Deliberadamente NO intenta distinguir
     * "cámbiame este ejercicio" de "cámbiame este ejercicio de pecho" — en
     * ambos casos la presencia de la ancla explícita basta para que la
     * identidad del objetivo se resuelva SIEMPRE contra el frente, nunca
     * contra el resto de la frase (incluida cualquier mención de foco).
     */
    private function hasExplicitAnchorMarker(string $normalizedBody): bool
    {
        $words = implode('|', self::ANCHOR_WORDS);

        return preg_match('/\b('.$words.')\b/u', $normalizedBody) === 1;
    }

    /**
     * Coincidencia por SUBCADENA bidireccional (cubre "cámbiame las
     * sentadillas" -> "Sentadilla", nombre corto contenido en el mensaje) O
     * por TOKEN de al menos 4 caracteres del nombre presente como subcadena
     * en el mensaje (cubre "cámbiame la sentadilla" -> "Sentadilla con
     * banda"/"Sentadilla sumo", nombres compuestos más largos que la frase
     * del usuario — la comparación por subcadena, no por igualdad exacta de
     * token, además tolera singular/plural sin necesitar alternancia
     * explícita: "sentadilla" ya es subcadena de "sentadillas"). Mismo
     * espíritu de comparación que `TrainingPreferenceIdentityResolver::partialMatches()`
     * (B3) — reimplementado aquí de forma independiente y más simple
     * (universo de búsqueda pequeño: los `WorkoutExercise` de UNA sesión,
     * nunca el catálogo completo), nunca importado desde B3 (aislamiento de
     * dominio). Nunca produce una resolución automática por sí sola — solo
     * alimenta el conteo de matches que decide `resolved` (1) vs.
     * `ambiguous` (2+) en `resolve()`.
     */
    private function nameMatchesBody(WorkoutExercise $workoutExercise, string $normalizedBody): bool
    {
        $name = $workoutExercise->exercise_snapshot['name'] ?? null;
        $normalizedName = $name !== null ? $this->normalize($name) : '';

        if ($normalizedName === '') {
            return false;
        }

        if (str_contains($normalizedBody, $normalizedName) || str_contains($normalizedName, $normalizedBody)) {
            return true;
        }

        foreach (array_filter(explode(' ', $normalizedName), fn (string $token) => mb_strlen($token) >= 4) as $token) {
            if (str_contains($normalizedBody, $token)) {
                return true;
            }
        }

        return false;
    }

    private function extractOrdinal(string $normalizedBody): ?int
    {
        $words = implode('|', array_keys(self::ORDINAL_WORDS));

        if (preg_match('/\b('.$words.')\b/u', $normalizedBody, $matches) === 1) {
            return self::ORDINAL_WORDS[$matches[1]];
        }

        return null;
    }

    /**
     * lowercase + sin acentos — mismo criterio de normalización ya usado por
     * `TrainingPreferenceMessageClassifier::normalize()`, reescrito aquí
     * (no importado desde ahí: esta clase no conoce nada de B3, mismo
     * principio de aislamiento por dominio ya aplicado en todo el proyecto).
     */
    private function normalize(string $text): string
    {
        $lower = mb_strtolower(trim($text));

        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];

        return strtr($lower, $map);
    }
}
