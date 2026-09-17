<?php

/**
 * P1-B — guardrail arquitectónico, mismo criterio exacto que
 * tests/Feature/Referrals/ReferralIsolationArchTest.php: App\Acquisition es
 * un dominio nuevo, autocontenido — nunca debe acoplarse a App\Training, y
 * App\Training nunca debe depender de App\Acquisition en esta fase (P1-B
 * es infraestructura de Core/adquisición, no un cambio de Training — ver
 * informe de P1-B). Si este test falla, alguien introdujo un acoplamiento
 * que el diseño prohíbe explícitamente.
 */

arch('App\Acquisition no depende de App\Training')
    ->expect('App\Acquisition')
    ->not->toUse('App\Training');

arch('App\Training no depende de App\Acquisition')
    ->expect('App\Training')
    ->not->toUse('App\Acquisition');
