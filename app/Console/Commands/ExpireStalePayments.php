<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use Illuminate\Console\Command;

/**
 * Hito 11 (D2) — programado cada 5 minutos (ver routes/console.php),
 * reutilizando el mismo contenedor `scheduler` que Hito 10 ya desplegó.
 * Cierra un gap real encontrado en la revisión previa a este hito:
 * `PaymentStatus::Expired` existía en el enum, se pintaba en Filament, y
 * tenía un mensaje propio en `PaymentHandler::handlePaymentStatus()` —
 * pero nada lo asignaba nunca. Un `Payment` abandonado en `pending`
 * quedaba "abierto" para siempre, bloqueando a ese contacto de iniciar un
 * intento de pago nuevo (`PaymentIntentClassifier::hasOpenPayment()`).
 *
 * Solo transiciona `pending` -> `expired` — nunca `under_review` (una vez
 * que llegó un comprobante, `expires_at` deja de ser relevante; no existe
 * un SLA de revisión separado en este hito). Nunca toca `TrainingAccess`
 * ni ningún dato de `Payment` más allá de `status`.
 */
class ExpireStalePayments extends Command
{
    protected $signature = 'payments:expire-stale';

    protected $description = 'Expires Payment rows stuck in "pending" past their expires_at without a receipt.';

    public function handle(): int
    {
        $count = Payment::where('status', PaymentStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => PaymentStatus::Expired->value]);

        $this->info("Expired {$count} stale payment(s).");

        return self::SUCCESS;
    }
}
