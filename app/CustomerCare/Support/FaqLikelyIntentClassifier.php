<?php

namespace App\CustomerCare\Support;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Intent;
use App\Core\Messaging\IntentClassifierInterface;

/**
 * Hito 14 — se registra ÚLTIMO en el Router, justo antes del default a
 * `Intent::FallbackChat` — la heurística de `FaqRelevanceDetector` es
 * deliberadamente amplia (ver su docblock) y nunca debe competir con
 * Training/Payment/Referral, que ya tuvieron su oportunidad de clasificar
 * antes de llegar aquí.
 */
class FaqLikelyIntentClassifier implements IntentClassifierInterface
{
    public function __construct(
        private readonly FaqRelevanceDetector $detector,
    ) {}

    public function classify(ExecutionContext $context): ?Intent
    {
        $body = $context->message->messageBody ?? '';

        return $this->detector->looksLikeFaqQuestion($body) ? Intent::CustomerCare : null;
    }
}
