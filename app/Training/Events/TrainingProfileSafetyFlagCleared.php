<?php

namespace App\Training\Events;

use App\Models\TrainingProfile;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hito O1 (Notificación proactiva de revisión de salud) — hecho de dominio:
 * "este TrainingProfile fue desbloqueado de una bandera de seguridad por un
 * humano". Distinto y separado de `DeclaredHealthConditionResolved` — dos
 * dominios con causas y semántica distintas (señal de emergencia vs.
 * declaración rutinaria de salud), mismo criterio de aislamiento que el
 * propio código ya aplica entre `SafetySignalDetector` y
 * `HealthScreeningRequirement`.
 *
 * `$previousFlaggedAt` viaja aparte — NUNCA se lee de
 * `$profile->safety_flagged_at` en el listener, que `clearSafetyFlag()` ya
 * puso en `null` antes de despachar. Es la única forma de construir una
 * `idempotencyKey` de notificación que distinga ciclos flag→clear
 * repetidos (ver `App\Training\Listeners\SendSafetyReviewResolutionNotification`)
 * — sin esto, un segundo ciclo completo (nueva señal, nueva limpieza)
 * compartiría la misma clave que el primero y `CustomerNotifier` lo
 * bloquearía como si fuera un reintento del mismo evento.
 *
 * `ShouldDispatchAfterCommit` — mismo criterio defensivo que
 * `WorkoutSessionCompleted`/`DeclaredHealthConditionResolved`.
 *
 * Se despacha ÚNICAMENTE desde `TrainingProfile::clearSafetyFlag()`, y
 * ÚNICAMENTE cuando el perfil realmente estaba `FlaggedForReview` antes de
 * limpiarlo (nunca para una llamada repetida sobre un perfil ya `Normal`).
 */
class TrainingProfileSafetyFlagCleared implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly TrainingProfile $profile,
        public readonly ?CarbonInterface $previousFlaggedAt,
    ) {}
}
