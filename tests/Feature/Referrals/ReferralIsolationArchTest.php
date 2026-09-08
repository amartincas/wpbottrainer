<?php

/**
 * Hito 13 — guardrail arquitectónico: la dependencia correcta es
 * `App\Referrals -> consume -> App\Payments\Events\PaymentConfirmed`,
 * nunca al revés, y Referrals nunca escribe directamente en TrainingAccess
 * ni conoce la lógica de acceso técnico (TrainingAccessGate/TrainingEngine)
 * — solo reutiliza `TrainingAccessAdministrationService::extendByDays()`.
 * Si este test falla, alguien introdujo un acoplamiento que el diseño
 * prohíbe explícitamente.
 */

arch('App\Training no depende de App\Referrals')
    ->expect('App\Training')
    ->not->toUse('App\Referrals');

arch('App\Referrals no depende de App\Payments\Support\PaymentConfirmationService, PaymentHandler ni PaymentValidationService — solo del evento PaymentConfirmed y modelos de lectura')
    ->expect('App\Referrals')
    ->not->toUse([
        'App\Payments\Support\PaymentConfirmationService',
        'App\Payments\Handlers\PaymentHandler',
        'App\Payments\Support\PaymentValidationService',
        'App\Payments\Support\ReceiptExtractionService',
    ]);

arch('App\Referrals no depende de TrainingAccessGate ni TrainingEngine — solo de TrainingAccessAdministrationService::extendByDays()')
    ->expect('App\Referrals')
    ->not->toUse([
        'App\Training\Support\TrainingAccessGate',
        'App\Training\Engine\TrainingEngine',
    ]);

arch('App\Referrals no crea Payments — nunca escribe App\Models\Payment')
    ->expect('App\Referrals')
    ->not->toUse('App\Payments\Handlers');
