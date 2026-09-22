<?php

namespace App\Core\Messaging;

/**
 * Hito A (Entry/Domain Fallback) — contrato que permite a un Domain Module
 * reclamar el residuo de `Intent::FallbackChat` para un Tenant que le
 * pertenece, SIN que Core (App\Core\Messaging\*) conozca nada del dominio
 * concreto que lo implementa (ej. Training).
 *
 * Se prueba ÚNICAMENTE cuando el Router ya agotó sus 4 Tiers reales y
 * ninguno reconoció el mensaje (ver App\Core\Messaging\DomainFallbackResolver
 * y App\Jobs\ProcessWhatsAppMessage) — nunca antes, nunca en paralelo al
 * Router. Esto es lo que distingue este mecanismo de un PreRoutingScreen:
 * un PreRoutingScreen corre para TODO mensaje, antes de cualquier
 * clasificación; un DomainFallbackClaim solo actúa sobre lo que el Router
 * ya determinó, sin ambigüedad, que ningún classifier específico reclamó.
 */
interface DomainFallbackClaimInterface
{
    /**
     * @return ?Intent El Intent que este módulo reclama para el fallback de
     *         este Tenant/Contact, o null si no aplica — en cuyo caso el
     *         siguiente claim registrado (si existe) tiene su oportunidad.
     */
    public function claim(ExecutionContext $context): ?Intent;
}
