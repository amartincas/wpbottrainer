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

/**
 * Hito 15 — `AutomaticTrialProvisioner` decide elegibilidad consultando
 * `App\Models\Payment` (namespace neutral de modelos, no `App\Payments`)
 * comparando el estado contra su valor crudo ('confirmed') en vez de
 * importar `App\Payments\Enums\PaymentStatus` — preserva que `App\Training`
 * siga sin ninguna dependencia real del dominio `App\Payments`. Si este
 * test falla, alguien introdujo la primera dependencia real de Training
 * hacia Payments.
 */
arch('AutomaticTrialProvisioner no depende de App\Payments')
    ->expect('App\Training\Support\AutomaticTrialProvisioner')
    ->not->toUse('App\Payments');
