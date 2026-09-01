<?php

/**
 * Guardrail arquitectónico (Hito 8): "PaymentHandler no debe escribir
 * directamente TrainingAccess" — el ÚNICO lugar autorizado a tocar
 * TrainingAccess a partir de un Payment es
 * App\Payments\Support\PaymentConfirmationService. Si este test falla,
 * significa que alguien agregó una escritura directa a TrainingAccess
 * desde el Handler o desde cualquier otro punto del dominio Payments.
 */

arch('App\Payments\Handlers does not depend on TrainingAccess — only PaymentConfirmationService may touch it')
    ->expect('App\Payments\Handlers')
    ->not->toUse('App\Models\TrainingAccess');

arch('App\Payments\Support classes other than PaymentConfirmationService do not depend on TrainingAccess')
    ->expect([
        'App\Payments\Support\PaymentIntentClassifier',
        'App\Payments\Support\ReceiptExtractionService',
        'App\Payments\Support\PaymentValidationService',
    ])
    ->not->toUse('App\Models\TrainingAccess');
