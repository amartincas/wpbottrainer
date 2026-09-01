<?php

namespace App\Payments\Support;

use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use Carbon\Carbon;

/**
 * Decide half del flujo de comprobantes (Hito 8) — determinista, sin
 * llamada a IA. Nunca confirma ni rechaza por sí solo: solo produce
 * `validation_flags`, la información que el humano necesita para decidir.
 * Controles mínimos de fraude/idempotencia para MVP — no antifraude
 * avanzado (ver docs/DECISIONS.md).
 */
class PaymentValidationService
{
    private const STALE_RECEIPT_DAYS = 15;

    /**
     * @param array{amount: ?float, date: ?string, time: ?string, reference: ?string, entity: ?string, payer_name: ?string, uncertain: bool} $extracted
     * @return array<int, string> lista de flags — vacía significa "nada que llamar la atención del revisor"
     */
    public function validate(Payment $payment, array $extracted): array
    {
        $flags = [];

        if ($extracted['uncertain']) {
            $flags[] = 'uncertain_extraction';
        }

        if ($extracted['amount'] === null) {
            $flags[] = 'amount_unreadable';
        } elseif (bccomp((string) $extracted['amount'], (string) $payment->amount, 2) !== 0) {
            $flags[] = 'amount_mismatch';
        }

        if ($extracted['reference'] === null) {
            $flags[] = 'reference_missing';
        } elseif ($this->referenceAlreadyUsed($extracted['reference'], $payment)) {
            $flags[] = 'reference_already_used';
        }

        if ($extracted['date'] !== null) {
            $staleness = $this->checkStaleness($extracted['date'], $payment);
            if ($staleness !== null) {
                $flags[] = $staleness;
            }
        } else {
            $flags[] = 'date_unreadable';
        }

        return $flags;
    }

    /**
     * Un mismo `reference` ya usado en un Payment CONFIRMADO (de cualquier
     * contacto — el control es global, no solo por tenant/contacto) es una
     * señal fuerte, pero nunca se rechaza automáticamente: referencias
     * pueden coincidir legítimamente en casos raros entre bancos distintos.
     */
    private function referenceAlreadyUsed(string $reference, Payment $payment): bool
    {
        return Payment::where('reference', $reference)
            ->where('status', PaymentStatus::Confirmed)
            ->where('id', '!=', $payment->id)
            ->exists();
    }

    private function checkStaleness(string $extractedDate, Payment $payment): ?string
    {
        try {
            $date = Carbon::parse($extractedDate);
        } catch (\Throwable) {
            // Fecha no parseable (formato libre/ambiguo) — no se puede
            // evaluar automáticamente; se marca para que el humano la
            // revise a simple vista, en vez de fallar silenciosamente.
            return 'date_unreadable';
        }

        $reference = $payment->receipt_submitted_at ?? now();

        if ($date->diffInDays($reference, true) > self::STALE_RECEIPT_DAYS) {
            return 'stale_receipt';
        }

        return null;
    }
}
