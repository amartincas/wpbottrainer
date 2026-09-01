<?php

namespace App\Payments\Support;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Intent;
use App\Core\Messaging\IntentClassifierInterface;
use App\Models\Contact;
use App\Payments\Enums\PaymentStatus;

/**
 * Clasificación determinista de "¿este mensaje es de Payments?" (Hito 8) —
 * mismo patrón que App\Training\Support\TrainingIntentClassifier: palabras
 * clave + una señal de estado (aquí, un Payment abierto sin resolver) que
 * clasifica como Payment aunque el mensaje no tenga ninguna palabra clave
 * ni texto (ej. el usuario solo envía la foto del comprobante, sin
 * mensaje). Sin esa señal de estado, una imagen sin caption nunca
 * clasificaría como Payment.
 */
class PaymentIntentClassifier implements IntentClassifierInterface
{
    private const KEYWORDS = [
        'pagar', 'pago', 'pagué', 'pague', 'activar servicio', 'activar mi cuenta',
        'comprobante', 'nequi', 'daviplata', 'transferencia', 'consignación',
        'consignacion', 'quiero pagar', 'cómo pago', 'como pago',
    ];

    public function classify(ExecutionContext $context): ?Intent
    {
        $body = mb_strtolower($context->message->messageBody ?? '');

        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($body, $keyword)) {
                return Intent::Payment;
            }
        }

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
