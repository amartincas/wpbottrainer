<?php

namespace App\Training\Support;

use App\Models\Exercise;
use App\Models\TrainingProfile;
use App\Models\TrainingRestriction;
use App\Training\Enums\RestrictionStatus;
use Illuminate\Support\Facades\Log;

/**
 * Hito de seguridad de restricciones — única pieza que conoce AMBOS
 * mecanismos de restricción (TrainingProfile.restrictions, texto libre
 * legacy; y TrainingRestriction, el contrato canónico nuevo) y el único
 * punto de la aplicación autorizado a leerlos directamente.
 * `TrainingEngine` nunca ve ninguno de los dos — solo el resultado ya
 * nivelado de este resolver (un array plano de strings, sin significado
 * propio para el motor).
 *
 * Garantía de no regresión (verificada explícitamente antes de
 * implementar, ver docs/DECISIONS.md): un texto legacy que hoy protege por
 * coincidencia exacta contra Exercise.contraindications SIGUE protegiendo
 * exactamente igual — el texto original nunca se descarta, solo se le
 * AÑADE el BodyRegion normalizado cuando el texto coincide con uno de los
 * 8 valores curados conocidos. Nunca se resta protección, solo se suma.
 */
class SafetyRestrictionResolver
{
    public function __construct(private readonly BodyRegionCanonicalMapper $mapper) {}

    /**
     * @return array<int, string> tags canónicos activos para este perfil —
     *         una mezcla de valores de BodyRegion::value (para texto
     *         reconocido) y del texto original tal cual (para texto no
     *         reconocido, preservando la comparación exacta legacy).
     */
    public function activeSafetyBodyRegions(TrainingProfile $profile): array
    {
        $structuredRegions = TrainingRestriction::query()
            ->where('contact_id', $profile->contact_id)
            ->where('status', RestrictionStatus::Confirmed)
            ->pluck('body_region')
            ->map(fn ($region) => $region->value)
            ->all();

        $legacyTags = $this->canonicalizeLegacyText($profile->restrictions ?? [], $profile->contact_id);

        return array_values(array_unique(array_merge($structuredRegions, $legacyTags)));
    }

    /**
     * @return array<int, string>
     */
    public function exerciseBodyRegions(Exercise $exercise): array
    {
        return $this->canonicalizeLegacyText($exercise->contraindications ?? [], null);
    }

    /**
     * Convierte una lista de texto libre en tags canónicos: los valores
     * reconocidos por BodyRegionCanonicalMapper se traducen a su BodyRegion;
     * los no reconocidos se preservan TAL CUAL (nunca se descartan) para
     * que la comparación exacta que ya funcionaba hoy siga funcionando —
     * ver la garantía de no regresión en el docblock de la clase.
     *
     * @param  array<int, mixed>  $freeText
     * @return array<int, string>
     */
    private function canonicalizeLegacyText(array $freeText, ?int $contactIdForLogging): array
    {
        $tags = [];

        foreach ($freeText as $text) {
            if (! is_string($text) || $text === '') {
                continue;
            }

            if ($this->mapper->isRecognized($text)) {
                foreach ($this->mapper->mapMany([$text]) as $region) {
                    $tags[] = $region->value;
                }

                continue;
            }

            // No reconocido: se preserva tal cual, NUNCA se descarta y
            // NUNCA se infiere una región a partir de él — es exactamente
            // el mismo texto que hoy ya participa en la comparación
            // exacta legacy.
            $tags[] = $text;

            if ($contactIdForLogging !== null) {
                Log::info('LEGACY_RESTRICTION_UNRECOGNIZED', [
                    'contact_id' => $contactIdForLogging,
                    'text' => $text,
                ]);
            }
        }

        return $tags;
    }
}
