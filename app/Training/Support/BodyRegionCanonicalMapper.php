<?php

namespace App\Training\Support;

use App\Training\Enums\BodyRegion;

/**
 * Hito de seguridad de restricciones — CATÁLOGO DE CORRESPONDENCIA EXACTA
 * Y EXPLÍCITA, nunca palabra clave genérica, nunca substring, nunca IA.
 * Sembrado únicamente con los 8 valores REALES verificados contra
 * producción en Exercise.contraindications al momento de este cambio — no
 * son términos inventados ni una ontología médica.
 *
 * (Renombrado desde BodyRegionKeywordMapper: el nombre anterior sugería
 * coincidencia por palabra clave/heurística, que es exactamente lo que
 * este catálogo NO hace — cada entrada es una correspondencia exacta y
 * curada, no una adivinanza a partir de fragmentos de texto.)
 *
 * "manguito rotador" y "hernia discal" están aquí deliberadamente: ninguno
 * de los dos contiene el nombre de una zona corporal como substring
 * ("hombro"/"espalda"), así que un mapeador por palabras clave genérico
 * los perdería — se transcriben explícitamente en vez de intentar
 * adivinarlos.
 *
 * Cualquier término nuevo (futuro) requiere: (1) agregar la entrada aquí
 * explícitamente, (2) un test que lo cubra, (3) revisión de código en el
 * mismo PR — nunca un proceso automático, nunca una inferencia en tiempo
 * de ejecución, nunca una heurística de texto.
 */
class BodyRegionCanonicalMapper
{
    private const EXACT_MAP = [
        'lesión de hombro' => BodyRegion::Shoulder,
        'manguito rotador' => BodyRegion::Shoulder,
        'dolor lumbar agudo' => BodyRegion::LowerBack,
        'hernia discal' => BodyRegion::LowerBack,
        'lesión de rodilla' => BodyRegion::Knee,
        'lesión de muñeca' => BodyRegion::Wrist,
        'lesión de codo' => BodyRegion::Elbow,
        'lesión de tobillo' => BodyRegion::Ankle,
    ];

    /**
     * @param  array<int, mixed>  $freeTextValues
     * @return array<int, BodyRegion>
     */
    public function mapMany(array $freeTextValues): array
    {
        $regions = [];

        foreach ($freeTextValues as $text) {
            if (! is_string($text)) {
                continue;
            }

            $region = self::EXACT_MAP[$text] ?? null;

            if ($region !== null) {
                $regions[$region->value] = $region;
            }
        }

        return array_values($regions);
    }

    public function isRecognized(mixed $text): bool
    {
        return is_string($text) && array_key_exists($text, self::EXACT_MAP);
    }
}
