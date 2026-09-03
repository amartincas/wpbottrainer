<?php

namespace App\ExerciseCatalog;

use App\ExerciseCatalog\DTOs\ResolvedMedia;
use App\ExerciseCatalog\Enums\MediaVariant;
use App\Models\Exercise;
use Illuminate\Support\Facades\Log;

/**
 * Hito 9.1 — único punto donde "qué ejercicio" (decisión de TrainingEngine)
 * se convierte en "de dónde sale el video" (proveedor, resuelto en
 * caliente). `TrainingEngine`/`TrainingHandler` nunca ven una URL directa
 * — solo el `Exercise` y, cuando corresponde, este resultado.
 *
 * `Exercise.provider === null` (ejercicio manual/curado, ej. la "Plancha"
 * demo de Hito 7) usa directamente su propia `video_url` — estable, sin
 * llamar a ningún proveedor. Es el mismo comportamiento que existía antes
 * de Hito 9, sin ningún cambio para ese contenido.
 *
 * Cualquier fallo de resolución se degrada a `null` (logueado) — nunca
 * lanza. El llamador (TrainingHandler) decide qué hacer con un `null`
 * (típicamente: omitir el video de ESE ejercicio, sin bloquear el resto
 * del mensaje) — ver docs de Hito 9, "tolerancia a fallo por ejercicio".
 */
class MediaResolver
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    public function resolve(Exercise $exercise, MediaVariant $variant = MediaVariant::Default): ?ResolvedMedia
    {
        if ($exercise->provider === null) {
            return $exercise->video_url !== null
                ? new ResolvedMedia($exercise->video_url, 'video/mp4', null)
                : null;
        }

        try {
            return $this->registry->get($exercise->provider)->resolveMedia($exercise->provider_exercise_id, $variant);
        } catch (\Throwable $e) {
            Log::warning('MEDIA_RESOLVE_FAILED', [
                'exercise_id' => $exercise->id,
                'provider' => $exercise->provider,
                'provider_exercise_id' => $exercise->provider_exercise_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
