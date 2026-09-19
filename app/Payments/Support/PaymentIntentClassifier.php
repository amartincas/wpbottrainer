<?php

namespace App\Payments\Support;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Intent;
use App\Core\Messaging\IntentClassifierInterface;

/**
 * Clasificación determinista de "¿este mensaje es de Payments?" (Hito 8) —
 * solo keywords explícitas en el texto del mensaje.
 *
 * Precedencia de Intents (ver docs/DECISIONS.md) — la señal de estado (un
 * Payment abierto sin resolver, que clasifica como Payment aunque el
 * mensaje no tenga ninguna palabra clave ni texto) se extrajo a
 * PaymentContextualIntentClassifier — un classifier separado, registrado en
 * el último tier del Router, para que una señal explícita de OTRO dominio
 * (Referral, CustomerCare) nunca pierda frente a este contexto.
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

        return null;
    }
}
