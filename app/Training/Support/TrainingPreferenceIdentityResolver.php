<?php

namespace App\Training\Support;

use App\Models\Exercise;
use App\Training\Enums\Equipment;
use Illuminate\Support\Collection;

/**
 * Hito B3 (diseño v3 FINAL, Sección A.4/6) — resolución de identidad para
 * ambas dimensiones del MVP. Deliberadamente NO copia
 * `RequestedFocusTermMapper`: ese mapeador resuelve contra un vocabulario
 * CERRADO Y PEQUEÑO (~13 términos de `MuscleFocus`); el catálogo de
 * `Exercise` es de escala de catálogo (cientos de filas), así que un mapa
 * estático no escala — este resolver usa normalización + comparación
 * exacta contra el catálogo real, nunca un mapa hardcodeado de nombres de
 * ejercicio.
 *
 * Principio absoluto (Regla del encargo, Sección 6): la seguridad de
 * identidad es más importante que la comodidad — NUNCA fuzzy matching,
 * Levenshtein, embeddings, sinónimos automáticos, reordenamiento de
 * palabras, ni eliminación de preposiciones internas. Solo se resuelve
 * automáticamente cuando existe una única identidad inequívoca después de
 * la normalización explícitamente permitida (ver `normalizeExerciseText()`).
 */
class TrainingPreferenceIdentityResolver
{
    /**
     * Vocabulario cerrado y curado de equipamiento en español, mismo
     * espíritu que `RequestedFocusTermMapper::EXACT_MAP` — cada entrada
     * futura requiere agregarse aquí explícitamente + un test, nunca un
     * proceso automático. Claves ya en el espacio normalizado (minúsculas,
     * sin acentos) que produce `TrainingPreferenceMessageClassifier`.
     * Cobertura deliberadamente parcial del catálogo completo de
     * `Equipment` (28 casos) — solo los términos con probabilidad real de
     * aparecer en una declaración de disgusto, ampliable con evidencia.
     */
    private const EQUIPMENT_TERMS = [
        'mancuernas' => Equipment::Dumbbells,
        'mancuerna' => Equipment::Dumbbells,
        'barra' => Equipment::Barbell,
        'kettlebell' => Equipment::Kettlebell,
        'pesa rusa' => Equipment::Kettlebell,
        'bandas' => Equipment::ResistanceBands,
        'banda' => Equipment::ResistanceBands,
        'banda de resistencia' => Equipment::ResistanceBands,
        'bandas de resistencia' => Equipment::ResistanceBands,
        'banco' => Equipment::Bench,
        'barra de dominadas' => Equipment::PullUpBar,
        'balon medicinal' => Equipment::MedicineBall,
        'maquina de cable' => Equipment::CableMachine,
        'polea' => Equipment::CableMachine,
        'maquina' => Equipment::Machine,
        'colchoneta' => Equipment::Mat,
        'mat' => Equipment::Mat,
        'caja' => Equipment::Box,
        'chaleco con peso' => Equipment::WeightedVest,
        'chaleco' => Equipment::WeightedVest,
    ];

    private const MAX_CLARIFICATION_OPTIONS = 5;

    /**
     * @param  ?array{workout_exercise_id: int, exercise_id: ?int, name: string, ...}  $frontExercise
     */
    public function resolve(?string $candidateTerm, ?array $frontExercise): TrainingPreferenceIdentityResolution
    {
        if ($candidateTerm === null || trim($candidateTerm) === '') {
            return $this->resolveFromFrontAnchor($frontExercise);
        }

        $equipment = $this->resolveEquipment($candidateTerm);

        if ($equipment !== null) {
            return $equipment;
        }

        return $this->resolveExercise($candidateTerm);
    }

    /**
     * Camino 1 (contexto activo, Sección 6): la identidad ya es conocida —
     * el `WorkoutExercise` actualmente al frente, sin importar la fase
     * (Main o Support: mismo principio de "comparar solo contra el actual"
     * ya usado por `SupportPhaseConfirmationDetector`). Cero comparación de
     * texto necesaria. Sin frente activo -> unresolved (nunca se adivina).
     */
    private function resolveFromFrontAnchor(?array $frontExercise): TrainingPreferenceIdentityResolution
    {
        if ($frontExercise === null || $frontExercise['exercise_id'] === null) {
            return TrainingPreferenceIdentityResolution::unresolved();
        }

        return TrainingPreferenceIdentityResolution::resolvedExercise(
            $frontExercise['exercise_id'],
            $frontExercise['name'],
        );
    }

    private function resolveEquipment(string $candidateTerm): ?TrainingPreferenceIdentityResolution
    {
        $normalized = trim($candidateTerm);
        $equipment = self::EQUIPMENT_TERMS[$normalized] ?? null;

        if ($equipment === null) {
            return null;
        }

        return TrainingPreferenceIdentityResolution::resolvedEquipment($equipment->value, $normalized);
    }

    /**
     * Camino 2 (sin contexto, Sección A.4): pipeline de normalización de 7
     * pasos ya aprobado — trim, colapso de espacios, minúsculas, sin
     * acentos, sin puntuación terminal, sin un único artículo inicial,
     * alternancia singular/plural simple. Solo resuelve automáticamente con
     * match único; en cualquier otro caso, clarificación con opciones por
     * coincidencia parcial de tokens — nunca resolución silenciosa.
     */
    private function resolveExercise(string $candidateTerm): TrainingPreferenceIdentityResolution
    {
        $normalizedCandidate = $this->normalizeExerciseText($candidateTerm);

        if ($normalizedCandidate === '') {
            return TrainingPreferenceIdentityResolution::unresolved();
        }

        // Corrección post-E2E real (hallazgo de MAX_CLARIFICATION_OPTIONS) —
        // `orderBy('id')` explícito: sin esto, el orden de $catalog no está
        // garantizado por SQL (verificado: no hay ningún índice usable para
        // esta consulta — el único índice de la tabla es compuesto
        // `(muscle_group, is_active)`, que esta consulta no puede aprovechar
        // por la regla de prefijo izquierdo), así que qué candidato queda
        // fuera de `partialMatches()` sería arbitrario y no reproducible.
        // Único criterio de orden: id ascendente — nunca un score de
        // relevancia, nunca un criterio de identidad nuevo.
        $catalog = Exercise::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'name_es']);
        $toggled = $this->togglePlural($normalizedCandidate);

        $exact = $this->exactMatches($catalog, $normalizedCandidate);

        if (count($exact) === 1) {
            return $this->toResolved($exact[0]);
        }

        if (count($exact) === 0) {
            $exactToggled = $toggled !== null ? $this->exactMatches($catalog, $toggled) : [];

            if (count($exactToggled) === 1) {
                return $this->toResolved($exactToggled[0]);
            }
        }

        // Ni un match único directo ni por singular/plural: se ofrecen
        // opciones por coincidencia parcial de tokens/subcadena —
        // comparando TANTO el candidato original COMO su alternancia
        // singular/plural (hallazgo del E2E real: el catálogo usa
        // mayoritariamente la forma singular como núcleo del nombre
        // compuesto, ej. "Sentadilla con banda", mientras el usuario puede
        // escribir en plural, ej. "sentadillas" — sin comparar también la
        // forma alternada, la lista de opciones queda incompleta). Sigue
        // siendo EXCLUSIVAMENTE ayuda de clarificación — `partialMatches()`
        // nunca puede producir `resolved`, solo alimenta `clarify()`.
        [$options, $totalMatches] = $this->partialMatches($catalog, $normalizedCandidate, $toggled);

        return $options === []
            ? TrainingPreferenceIdentityResolution::unresolved()
            : TrainingPreferenceIdentityResolution::clarify($options, $totalMatches);
    }

    /**
     * @param  Collection<int, Exercise>  $catalog
     * @return array<int, Exercise>
     */
    private function exactMatches($catalog, string $normalizedCandidate): array
    {
        return $catalog->filter(function (Exercise $exercise) use ($normalizedCandidate) {
            $names = array_filter([$exercise->name, $exercise->name_es]);

            foreach ($names as $name) {
                if ($this->normalizeExerciseText($name) === $normalizedCandidate) {
                    return true;
                }
            }

            return false;
        })->values()->all();
    }

    /**
     * EXCLUSIVAMENTE para clarificación — jamás produce una resolución
     * automática (ver únicos dos llamadores de este método en
     * `resolveExercise()`, ambos alimentan `clarify()`/`unresolved()`,
     * nunca `resolved()`). Compara tanto `$normalizedCandidate` como su
     * alternancia singular/plural `$toggledCandidate` (mismo alcance ya
     * aprobado para `exactMatches()`, Sección A.4/revisión v4) — sin esto,
     * un candidato en plural ("sentadillas") nunca comparte token/subcadena
     * con nombres compuestos que usan la forma singular como núcleo
     * ("Sentadilla con banda"), dejando la lista de opciones incompleta
     * (hallazgo del E2E real en staging). Sigue sin normalizar preposiciones
     * internas, sinónimos, reordenamiento ni ningún stemming más allá de
     * esta única alternancia ya aprobada.
     *
     * Corrección post-E2E real (hallazgo de `MAX_CLARIFICATION_OPTIONS`) —
     * el recorrido de `$catalog` YA NUNCA se corta al llegar a
     * `MAX_CLARIFICATION_OPTIONS`: sigue completo hasta el final para poder
     * reportar `$totalMatches` con exactitud, aunque `$labels` deje de
     * crecer una vez lleno. Antes, cortar el `foreach` en cuanto se
     * llenaban las 5 opciones hacía que una variante real quedara
     * silenciosamente fuera de la lista, sin que nadie (ni el código, ni el
     * usuario) pudiera saber que existía. `$catalog` ya llega ordenado por
     * `id` ascendente desde `resolveExercise()` — determinista, siempre las
     * MISMAS 5 primeras coincidencias, nunca un recorte arbitrario.
     *
     * @param  Collection<int, Exercise>  $catalog
     * @return array{0: array<int, string>, 1: int} [labels, totalMatches]
     */
    private function partialMatches($catalog, string $normalizedCandidate, ?string $toggledCandidate): array
    {
        $labels = [];
        $totalMatches = 0;
        // Token de conexión ("de", "la", "con"...) nunca por sí solo cuenta
        // como coincidencia — evitaría ofrecer ejercicios genuinamente no
        // relacionados solo porque comparten una preposición.
        $candidateTokens = array_filter(explode(' ', $normalizedCandidate), fn (string $t) => mb_strlen($t) >= 4);
        $toggledTokens = $toggledCandidate !== null
            ? array_filter(explode(' ', $toggledCandidate), fn (string $t) => mb_strlen($t) >= 4)
            : [];

        foreach ($catalog as $exercise) {
            $names = array_filter([$exercise->name_es, $exercise->name]);

            foreach ($names as $name) {
                $normalizedName = $this->normalizeExerciseText($name);

                if ($normalizedName === '') {
                    continue;
                }

                // Coincidencia por SUBCADENA (bidireccional — cubre
                // "sentadilla" -> "Sentadilla sumo" y "press banca" ->
                // "Press de banca") O por TOKEN compartido de al menos 4
                // caracteres (cubre "sentadilla con salto" -> "Sentadilla
                // sumo", donde ninguna es subcadena de la otra) — evaluado
                // contra AMBAS formas del candidato (original y alternada)
                // — en cualquier caso, solo una OPCIÓN de clarificación,
                // nunca una resolución automática (Regla del encargo,
                // Sección 6).
                $nameTokens = array_filter(explode(' ', $normalizedName), fn (string $t) => mb_strlen($t) >= 4);
                $sharesToken = array_intersect($candidateTokens, $nameTokens) !== []
                    || ($toggledTokens !== [] && array_intersect($toggledTokens, $nameTokens) !== []);

                $matchesSubstring = str_contains($normalizedName, $normalizedCandidate)
                    || str_contains($normalizedCandidate, $normalizedName)
                    || ($toggledCandidate !== null && (
                        str_contains($normalizedName, $toggledCandidate)
                        || str_contains($toggledCandidate, $normalizedName)
                    ));

                if ($matchesSubstring || $sharesToken) {
                    $totalMatches++;

                    if (count($labels) < self::MAX_CLARIFICATION_OPTIONS) {
                        $labels[] = $name;
                    }

                    break;
                }
            }
        }

        return [array_values(array_unique($labels)), $totalMatches];
    }

    private function toResolved(Exercise $exercise): TrainingPreferenceIdentityResolution
    {
        return TrainingPreferenceIdentityResolution::resolvedExercise(
            $exercise->id,
            $exercise->name_es ?? $exercise->name,
        );
    }

    /**
     * Único punto de "alternancia singular/plural simple" (Sección A.4,
     * paso 7) — una regla fija única (quitar o añadir una "s" terminal),
     * NUNCA un stemmer general. Se prueba en AMBAS direcciones porque no se
     * sabe si el candidato llegó en singular o plural.
     */
    private function togglePlural(string $normalized): ?string
    {
        if (str_ends_with($normalized, 's') && mb_strlen($normalized) > 1) {
            return mb_substr($normalized, 0, -1);
        }

        return $normalized.'s';
    }

    /**
     * Pipeline de normalización de 7 pasos (diseño v3, Sección A.4, pasos
     * 1-5): trim, colapso de espacios, minúsculas, sin acentos, sin
     * puntuación terminal, sin un único artículo inicial. Los pasos 6-7
     * (comparación exacta + alternancia singular/plural) viven en los
     * métodos llamadores, no aquí. Deliberadamente NUNCA elimina
     * preposiciones internas ("de", "en") — "press banca" nunca se
     * normaliza automáticamente a "press de banca" (Sección 4 de la
     * revisión v3).
     */
    private function normalizeExerciseText(string $text): string
    {
        $normalized = mb_strtolower(trim($text));

        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];
        $normalized = strtr($normalized, $map);

        $normalized = trim($normalized, " \t\n\r\0\x0B.,;:!?¿¡");
        $normalized = preg_replace('/^(el|la|los|las|un|una)\s+/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
