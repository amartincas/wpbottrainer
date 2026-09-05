<?php

namespace App\Training\Support;

use App\Training\Enums\BodyRegion;

/**
 * Bloque 5 — mismo patrón EXACTO de gobernanza que BodyRegionCanonicalMapper
 * (Bloque 1): correspondencia EXACTA y explícita, nunca palabra clave,
 * nunca substring, nunca IA, nunca aproximación médica.
 *
 * Traduce texto de LIMITACIÓN FUNCIONAL ("no puedo levantar el brazo por
 * encima de la cabeza") a un `BodyRegion` — un catálogo DISTINTO del de
 * `BodyRegionCanonicalMapper` (que traduce nombres de CONDICIONES, ej.
 * "lesión de hombro"), porque el vocabulario es conceptualmente diferente:
 * una condición nombra un diagnóstico/lesión; una limitación funcional
 * describe un movimiento o acción. Confundir ambos catálogos sería mezclar
 * "qué tiene el usuario" con "qué no puede hacer".
 *
 * El catálogo empieza VACÍO deliberadamente: a diferencia de los 8 valores
 * de BodyRegionCanonicalMapper (verificados contra
 * Exercise.contraindications real de producción), no existe todavía un
 * vocabulario real de frases funcionales curado — inventar frases de
 * ejemplo sería exactamente la "ontología médica" que este bloque prohíbe
 * construir. Cada entrada futura requiere: (1) agregarla aquí explícitamente
 * a partir de un caso real, (2) un test que la cubra, (3) revisión de
 * código en el mismo PR — nunca un proceso automático, nunca IA, nunca
 * heurística de texto.
 *
 * El resultado de map() es SOLO una sugerencia para acelerar la revisión
 * humana (ver DeclaredHealthConditionRecorder::declare()) — jamás dispara
 * por sí solo la creación de una TrainingRestriction. Ver
 * docs/DECISIONS.md D048.
 */
class FunctionalLimitationCanonicalMapper
{
    private const EXACT_MAP = [
        // Vacío hoy — ver docblock de la clase.
    ];

    public function map(string $functionalLimitationText): ?BodyRegion
    {
        return self::EXACT_MAP[$functionalLimitationText] ?? null;
    }

    public function isRecognized(mixed $text): bool
    {
        return is_string($text) && array_key_exists($text, self::EXACT_MAP);
    }
}
