<?php

namespace App\Core\Messaging;

use Illuminate\Contracts\Container\Container;

/**
 * Hito A (Entry/Domain Fallback) — resuelve, únicamente para el residuo de
 * `Intent::FallbackChat` (ver App\Jobs\ProcessWhatsAppMessage), si algún
 * Domain Module reclama ese mensaje como propio para el Tenant actual.
 *
 * Mismo patrón Router→Container→Classifier que App\Core\Messaging\Router/
 * Dispatcher/PreRoutingScreener: un mapa de CLASES (no instancias),
 * registrado en AppServiceProvider, resueltas por el Container en cada uso.
 * Esta clase contiene CERO conocimiento de dominio — ningún import de
 * App\Training ni de ningún otro módulo concreto.
 *
 * Deliberadamente NO es un PreRoutingScreen: se invoca solo DESPUÉS de que
 * el Router ya agotó sus 4 Tiers reales y ninguno reconoció el mensaje —
 * nunca para todo mensaje entrante. Esto es lo que lo distingue de
 * SafetySignalPreRoutingScreen/ReferralAttributionPreRoutingScreen/
 * AcquisitionSourcePreRoutingScreen (los 3 sí corren para TODO mensaje,
 * antes de cualquier clasificación).
 */
class DomainFallbackResolver
{
    /**
     * @param array<int, class-string<DomainFallbackClaimInterface>> $claimClasses
     *        Probadas en orden; el primer Intent no-null gana.
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $claimClasses,
    ) {}

    public function resolve(ExecutionContext $context): ?Intent
    {
        foreach ($this->claimClasses as $claimClass) {
            /** @var DomainFallbackClaimInterface $claim */
            $claim = $this->container->make($claimClass);

            $intent = $claim->claim($context);

            if ($intent !== null) {
                return $intent;
            }
        }

        return null;
    }
}
