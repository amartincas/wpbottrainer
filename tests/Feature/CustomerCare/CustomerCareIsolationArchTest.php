<?php

/**
 * Hito 14 — guardrail arquitectónico: la dependencia correcta es
 * `App\Training -> consume -> App\CustomerCare` (vía `CoachFaqCandidate`,
 * `FaqMatcher`, `CustomerServiceRequestRecorder`, detectores), nunca al
 * revés — exactamente como `App\Training` ya depende de `App\Core\Alerts`
 * y `App\Core\Notifications`. `App\CustomerCare` es una capa de servicio
 * genérica (FAQ + escalamiento humano) que no debe saber nada de
 * WorkoutSession, CoachContext, TrainingProfile ni del motor de
 * entrenamiento — ni tampoco de Payments/Referrals, con los que no tiene
 * ninguna relación funcional.
 *
 * Si este test falla, alguien introdujo un acoplamiento que el diseño v6
 * (Hito 14) prohíbe explícitamente.
 */

arch('App\CustomerCare no depende de App\Training')
    ->expect('App\CustomerCare')
    ->not->toUse('App\Training');

arch('App\CustomerCare no depende de App\Payments')
    ->expect('App\CustomerCare')
    ->not->toUse('App\Payments');

arch('App\CustomerCare no depende de App\Referrals')
    ->expect('App\CustomerCare')
    ->not->toUse('App\Referrals');

arch('App\CustomerCare no conoce el motor de entrenamiento ni el contexto de coach')
    ->expect('App\CustomerCare')
    ->not->toUse([
        'App\Training\Engine\TrainingEngine',
        'App\Training\Context\CoachContext',
        'App\Training\Context\CoachContextProvider',
        'App\Training\Support\CoachService',
        'App\Training\Models\WorkoutSession',
    ]);

arch('App\CustomerCare no depende de Handlers de otros dominios')
    ->expect('App\CustomerCare')
    ->not->toUse([
        'App\Training\Handlers',
        'App\Payments\Handlers',
        'App\Referrals\Handlers',
    ]);
