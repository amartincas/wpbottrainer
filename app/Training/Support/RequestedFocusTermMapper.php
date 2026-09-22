<?php

namespace App\Training\Support;

use App\Training\Enums\MuscleFocus;

/**
 * Hito B1 (Requested Focus) — mismo patrón EXACTO de gobernanza que
 * `BodyRegionCanonicalMapper`/`FunctionalLimitationCanonicalMapper`:
 * correspondencia EXACTA y explícita, nunca palabra clave, nunca substring,
 * nunca IA, nunca aproximación.
 *
 * Cierra el límite LLM → dominio (Tarea 4 del diseño aprobado): el
 * clasificador de intención SOLO puede producir términos de lenguaje crudo
 * (`requested_focus_terms`, ej. ["pecho", "piernas"]) — NUNCA valores de
 * `MuscleFocus` directamente. Este mapeador es el ÚNICO punto del dominio
 * que traduce esos términos a `RequestedFocusGroup`, de forma determinista y
 * cerrada. El LLM interpreta lenguaje; este código determina el valor
 * canónico; `TrainingEngine` decide el entrenamiento — ninguna de las tres
 * responsabilidades se mezcla.
 *
 * Vocabulario aprobado en la auditoría B1 (deliberadamente NO ampliado en
 * esta implementación): cada entrada futura requiere agregarla aquí
 * explícitamente, un test que la cubra, y revisión de código en el mismo PR
 * — nunca un proceso automático ni una heurística de texto.
 *
 * Sin normalización de texto (trim/lowercase): mismo criterio EXACTO que
 * `BodyRegionCanonicalMapper`/`FunctionalLimitationCanonicalMapper`, que
 * tampoco normalizan — la correspondencia es literal contra el vocabulario
 * cerrado. Un término con mayúsculas/espacios distintos a los aquí listados
 * se rechaza igual que cualquier otro término desconocido (nunca se
 * aproxima).
 *
 * "todo el cuerpo"/"full body" son términos RECONOCIDOS que, sin embargo,
 * deliberadamente NUNCA producen un `RequestedFocusGroup`: representan la
 * AUSENCIA de un requested focus específico (el comportamiento autónomo de
 * `TrainingEngine::decideFocus()` ya cubre "todo el cuerpo" sin necesitar un
 * grupo explícito) — ver Tarea 3 del diseño aprobado.
 */
class RequestedFocusTermMapper
{
    /**
     * Cada entrada: término crudo => [key canónico, MuscleFocus[] del grupo].
     * Varios términos pueden apuntar al MISMO `key` (sinónimos exactos,
     * ej. "piernas"/"pierna"/"tren inferior"/"lower body"/"lower") — se
     * fusionan en un solo `RequestedFocusGroup` en `mapMany()`, nunca se
     * duplican. Un `key` distinto (ej. "quads" vs "legs") nunca se fusiona,
     * aunque sus `muscles` se solapen — "piernas" + "cuádriceps" siguen
     * siendo 2 grupos independientes (ver diseño aprobado, Sección 1).
     */
    private const EXACT_MAP = [
        'pecho' => ['chest', [MuscleFocus::Chest]],
        'espalda' => ['back', [MuscleFocus::Back]],
        'hombros' => ['shoulders', [MuscleFocus::Shoulders]],
        'brazos' => ['arms', [MuscleFocus::Biceps, MuscleFocus::Triceps]],
        'core' => ['core', [MuscleFocus::Abs]],
        'abdomen' => ['core', [MuscleFocus::Abs]],
        'piernas' => ['legs', [MuscleFocus::Quads, MuscleFocus::Hamstrings, MuscleFocus::Glutes, MuscleFocus::Calves]],
        'pierna' => ['legs', [MuscleFocus::Quads, MuscleFocus::Hamstrings, MuscleFocus::Glutes, MuscleFocus::Calves]],
        'tren inferior' => ['legs', [MuscleFocus::Quads, MuscleFocus::Hamstrings, MuscleFocus::Glutes, MuscleFocus::Calves]],
        'lower body' => ['legs', [MuscleFocus::Quads, MuscleFocus::Hamstrings, MuscleFocus::Glutes, MuscleFocus::Calves]],
        'lower' => ['legs', [MuscleFocus::Quads, MuscleFocus::Hamstrings, MuscleFocus::Glutes, MuscleFocus::Calves]],
        'glúteos' => ['glutes', [MuscleFocus::Glutes]],
        'cuádriceps' => ['quads', [MuscleFocus::Quads]],
    ];

    /**
     * Términos que representan explícitamente "sin foco específico" — nunca
     * producen un grupo, ver docblock de la clase.
     */
    private const FULL_BODY_TERMS = ['todo el cuerpo', 'full body'];

    /**
     * @param  array<int, mixed>  $terms  Términos crudos del LLM (nunca MuscleFocus).
     * @return ?array<int, RequestedFocusGroup> null = ausencia de requested
     *         focus (ningún término reconocido produjo grupo, o solo se
     *         reconocieron términos de "todo el cuerpo"). El orden del array
     *         resultante conserva el orden de aparición en $terms — nunca se
     *         reordena aquí (la prioridad de slots se decide en
     *         TrainingEngine por `key` ASC, no por este orden — ver diseño
     *         aprobado, Sección 2).
     */
    public function mapMany(array $terms): ?array
    {
        $groups = [];
        $seenKeys = [];

        foreach ($terms as $term) {
            if (! is_string($term)) {
                continue;
            }

            if (in_array($term, self::FULL_BODY_TERMS, true)) {
                continue;
            }

            $entry = self::EXACT_MAP[$term] ?? null;

            if ($entry === null) {
                // Término desconocido: nunca se aproxima, nunca substring
                // matching, nunca se inventa un MuscleFocus — se rechaza en
                // silencio (comportamiento explícito y testeado, no un
                // olvido). El llamador puede registrar el término crudo
                // descartado si lo necesita; este mapeador no tiene acceso a
                // contact_id/tenant_id para loguearlo con contexto útil.
                continue;
            }

            [$key, $muscleFocuses] = $entry;

            if (isset($seenKeys[$key])) {
                continue;
            }

            $seenKeys[$key] = true;
            $groups[] = new RequestedFocusGroup(
                $key,
                array_map(fn (MuscleFocus $focus) => $focus->value, $muscleFocuses),
            );
        }

        return $groups === [] ? null : $groups;
    }

    public function isRecognized(mixed $term): bool
    {
        if (! is_string($term)) {
            return false;
        }

        return in_array($term, self::FULL_BODY_TERMS, true) || array_key_exists($term, self::EXACT_MAP);
    }
}
