<?php

namespace App\Training\Events;

use App\Models\WorkoutSession;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Referral Introduction — primer evento de dominio de `App\Training`
 * (mismo patrón exacto que `App\Payments\Events\PaymentConfirmed`, Hito 11):
 * hecho de negocio genérico — "esta WorkoutSession se completó" — para que
 * un futuro consumidor (Referral, u otro) pueda reaccionar sin que Training
 * conozca sus reglas. `App\Training` nunca importa ni depende de quien
 * escuche este evento; la dependencia va en el sentido contrario (mismo
 * criterio ya usado y documentado en docs/DECISIONS.md D056 para
 * `PaymentConfirmed`).
 *
 * Se despacha ÚNICAMENTE desde `ExecutionReportRecorder::maybeCompleteSession()`
 * — la única fuente de verdad de "una sesión se completó" en todo el
 * proyecto (escribe `WorkoutSession.status`/`completed_at`) — nunca desde
 * ningún otro punto, para no crear una segunda fuente de verdad sobre
 * "training completed".
 *
 * `ShouldDispatchAfterCommit`: no hay ninguna `DB::transaction()` explícita
 * envolviendo `maybeCompleteSession()` hoy, así que esta interfaz no
 * cambia el comportamiento actual (Laravel despacha de inmediato si no hay
 * transacción activa) — se incluye de forma defensiva, por si un futuro
 * cambio envuelve ese método en una transacción, para que ningún listener
 * reaccione a una finalización que termine revertida.
 */
class WorkoutSessionCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly WorkoutSession $session) {}
}
