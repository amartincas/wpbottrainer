<?php

namespace App\Payments\Support;

use App\Core\Messaging\ContextualIntentClassifierInterface;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Intent;
use App\Models\Contact;
use App\Payments\Enums\PaymentStatus;

/**
 * Precedencia de Intents (ver docs/DECISIONS.md) — extraído literalmente de
 * PaymentIntentClassifier: esta regla clasifica Intent::Payment ÚNICAMENTE
 * por el estado del Contact (un Payment abierto sin resolver), sin ninguna
 * señal textual del mensaje (ej. el usuario solo envía la foto del
 * comprobante, sin caption). Por eso vive en un classifier separado, marcado
 * con ContextualIntentClassifierInterface, registrado en el ÚLTIMO tier del
 * Router — así una señal EXPLÍCITA de otro dominio (Referral, CustomerCare)
 * nunca pierde frente a este contexto.
 *
 * Ninguna condición, consulta ni significado cambió respecto a su versión
 * original dentro de PaymentIntentClassifier — extracción mecánica.
 */
class PaymentContextualIntentClassifier implements ContextualIntentClassifierInterface
{
    public function classify(ExecutionContext $context): ?Intent
    {
        $contact = Contact::where('tenant_id', $context->tenant->id)
            ->where('customer_phone', $context->message->from)
            ->first();

        if ($contact === null) {
            return null;
        }

        if ($this->hasOpenPayment($contact)) {
            return Intent::Payment;
        }

        return null;
    }

    private function hasOpenPayment(Contact $contact): bool
    {
        return $contact->payments()
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::UnderReview])
            ->exists();
    }
}
