<?php

namespace App\Payments\Events;

use App\Models\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hito 11 — hecho de negocio genérico de Payments: "este Payment fue
 * confirmado". Seam explícito para que un futuro dominio (Referidos, u
 * otro) pueda reaccionar sin que Payments conozca sus reglas — Payments
 * nunca importa ni depende de ese futuro dominio; la dependencia va en el
 * sentido contrario (ver docs/DECISIONS.md).
 *
 * Se despacha EXACTAMENTE una vez por confirmación real — nunca en el
 * camino de no-op idempotente de `PaymentConfirmationService::confirm()`
 * (un retry sobre un Payment ya confirmado no vuelve a disparar este
 * evento). Sin listeners registrados en este hito — el evento se dispara
 * hacia un vacío intencional; un futuro Hito de Referidos se limita a
 * registrar su propio listener apuntando aquí, sin tocar `App\Payments`.
 *
 * `ShouldDispatchAfterCommit`: `confirm()` corre dentro de una
 * `DB::transaction()` (concurrencia, ver D1) — esta interfaz nativa de
 * Laravel garantiza que ningún listener futuro reaccione a una
 * confirmación que termine revertida dentro de esa misma transacción.
 * `Event::fake()` en tests ignora este diferimiento (comportamiento
 * documentado de Laravel), así que las aserciones de test no necesitan
 * preocuparse por el commit real.
 */
class PaymentConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
