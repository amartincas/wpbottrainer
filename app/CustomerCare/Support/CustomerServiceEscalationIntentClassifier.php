<?php

namespace App\CustomerCare\Support;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Intent;
use App\Core\Messaging\IntentClassifierInterface;

/**
 * Hito 14 — se registra PRIMERO en el Router (antes que Training/Payment/
 * Referral) a propósito: el propio ejemplo de diseño, "Tengo un problema
 * con el pago", contiene literalmente la keyword "pago" de
 * PaymentIntentClassifier — una petición explícita de ayuda humana debe
 * ganarle a esa colisión accidental, sin tocar ningún clasificador
 * existente (ver docs/DECISIONS.md).
 */
class CustomerServiceEscalationIntentClassifier implements IntentClassifierInterface
{
    public function __construct(
        private readonly CustomerServiceEscalationDetector $detector,
    ) {}

    public function classify(ExecutionContext $context): ?Intent
    {
        $body = $context->message->messageBody ?? '';

        return $this->detector->detect($body) ? Intent::CustomerCare : null;
    }
}
