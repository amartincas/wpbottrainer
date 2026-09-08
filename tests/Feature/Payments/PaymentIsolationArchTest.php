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

/**
 * Hito 11 — seam `PaymentConfirmed`: Payments produce el hecho de negocio,
 * un futuro Hito de Referidos lo consume — nunca al revés. `App\Referrals`
 * no existe todavía (deliberadamente, fuera de alcance de este hito); este
 * guardrail queda como protección activa desde ya, para que ese futuro
 * hito no pueda insertar lógica de referidos dentro de `App\Payments` sin
 * que este test lo detecte de inmediato.
 */
arch('App\Payments no depende de App\Referrals — la dependencia va en el sentido contrario')
    ->expect('App\Payments')
    ->not->toUse('App\Referrals');
