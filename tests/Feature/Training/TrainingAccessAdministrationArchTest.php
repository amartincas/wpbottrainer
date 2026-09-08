<?php

/**
 * Hito 12 — guardrail arquitectónico: `TrainingAccessAdministrationService`
 * (transiciones administrativas de TrainingAccess) y
 * `App\Payments\Support\PaymentConfirmationService` (transiciones
 * originadas por un Payment confirmado) son las DOS únicas puertas a
 * `TrainingAccess`, y NUNCA dependen una de la otra. Si este test falla,
 * significa que alguien introdujo una dependencia cruzada entre el flujo
 * administrativo y el flujo de pagos.
 */

arch('TrainingAccessAdministrationService no depende de App\Payments')
    ->expect('App\Training\Support\TrainingAccessAdministrationService')
    ->not->toUse('App\Payments');

arch('App\Payments no depende de TrainingAccessAdministrationService')
    ->expect('App\Payments')
    ->not->toUse('App\Training\Support\TrainingAccessAdministrationService');
