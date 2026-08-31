<?php

namespace App\Core\Messaging;

/**
 * Contract for a check that runs BEFORE Router/Dispatcher, regardless of
 * what Intent the message would otherwise classify as (Hito 7 — hallazgo de
 * la prueba E2E real: una señal de seguridad no puede depender de que el
 * Router haya clasificado el mensaje como "training", ni de que exista una
 * sesión de entrenamiento activa. Ver docs/DECISIONS.md).
 *
 * Returning true means this screen already fully handled the message (sent
 * any response itself) and the normal Router/Dispatcher pipeline must be
 * skipped entirely for it. Returning false means "not mine" — PreRoutingScreener
 * tries the next registered screen, and if none claim it, the message
 * continues to Router as it always has.
 *
 * Same Container-resolved-map pattern as IntentClassifierInterface/
 * HandlerInterface/ContextProviderInterface — implementations live in Domain
 * (e.g. App\Training\Support\SafetySignalPreRoutingScreen), never in Core.
 * Core only knows this interface exists, never what any concrete screen
 * looks for or how it responds.
 */
interface PreRoutingScreenInterface
{
    public function screen(ExecutionContext $context): bool;
}
