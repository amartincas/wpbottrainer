<?php

namespace App\Training\Events;

use App\Models\DeclaredHealthCondition;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hito O1 (Notificación proactiva de revisión de salud) — hecho de dominio:
 * "esta DeclaredHealthCondition fue resuelta por un humano", sin importar
 * el desenlace concreto — el propio `$condition->status` ya distingue
 * `ResolvedNoRestriction` de `ResolvedRestrictionCreated`; este evento no
 * duplica esa información, solo transporta la condición ya resuelta y
 * fresca (post-commit) para que un listener decida qué notificar.
 *
 * `ShouldDispatchAfterCommit` — mismo patrón EXACTO que
 * `App\Training\Events\WorkoutSessionCompleted`: aquí no es solo defensivo,
 * es NECESARIO — `DeclaredHealthConditionRecorder::resolveWithRestriction()`
 * sí abre una `DB::transaction()` real, y ningún listener debe reaccionar a
 * una resolución que termine revertida por esa transacción.
 *
 * Se despacha ÚNICAMENTE desde `DeclaredHealthConditionRecorder`
 * (`resolveWithRestriction()`/`resolveWithoutRestriction()`), y ÚNICAMENTE
 * cuando la resolución fue real (la condición seguía `pending_review` en el
 * momento de escribir, bajo lock) — un segundo intento sobre una condición
 * ya resuelta nunca despacha este evento de nuevo. Ver docblock de esos
 * métodos.
 */
class DeclaredHealthConditionResolved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly DeclaredHealthCondition $condition) {}
}
