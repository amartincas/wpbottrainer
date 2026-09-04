<?php

namespace App\ExerciseCatalog\Enums;

/**
 * Hito 9.3 (fix post-E2E, observabilidad) — motivo por el que la
 * resolución de media de un ejercicio de proveedor no produjo una URL de
 * video utilizable. Vocabulario cerrado y agnóstico de proveedor
 * (ningún Adapter concreto se nombra aquí, ni en esta clase ni en sus
 * valores) — cualquier Adapter presente o futuro clasifica sus propios
 * fallos usando estos mismos valores, nunca inventa los suyos.
 *
 * Antes de este fix, las 5 causas de abajo eran indistinguibles: todas
 * colapsaban en un simple `null`, sin ningún rastro en los logs (hallazgo
 * real: un video ausente por cuota de proveedor excedida era
 * indistinguible de un ejercicio que genuinamente no tiene video).
 */
enum MediaResolutionReason: string
{
    /** El proveedor respondió con éxito, pero el ejercicio no tiene video. */
    case HasNoVideo = 'provider_has_no_video';

    /** El proveedor rechazó la solicitud por cuota/límite de plan excedido (ej. HTTP 429). */
    case QuotaExceeded = 'provider_quota_exceeded';

    /** Fallo HTTP del proveedor no clasificable como cuota (4xx/5xx genérico). */
    case HttpError = 'provider_http_error';

    /** El proveedor confirmó que el id no existe (ej. HTTP 404). */
    case ExerciseNotFound = 'provider_exercise_not_found';

    /** Cualquier otro fallo no anticipado (ej. una excepción de red/conexión). */
    case UnexpectedError = 'provider_unexpected_error';
}
