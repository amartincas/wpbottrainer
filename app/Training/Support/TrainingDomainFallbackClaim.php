<?php

namespace App\Training\Support;

use App\Core\Messaging\DomainFallbackClaimInterface;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Intent;

/**
 * Hito A (Entry/Domain Fallback) — reclama el residuo de
 * `Intent::FallbackChat` (ver App\Core\Messaging\DomainFallbackResolver)
 * para cualquier Tenant configurado como `primary_domain === 'training'`.
 *
 * Corrige el hallazgo real de la auditoría E2E: un usuario nuevo de un
 * tenant Training cuyo primer mensaje no contiene ninguna keyword
 * reconocible por `TrainingIntentClassifier` (ej. "Quiero unirme a
 * WpbotTrainer") caía en `App\Handlers\FallbackChatHandler` — un chatbot
 * de e-commerce legacy y compartido, ajeno por completo al dominio
 * Training — que llegaba a fabricar una rutina completa vía IA libre y
 * crear un segundo `Contact` desconectado de `TrainingProfile`.
 *
 * Deliberadamente NO es "Tenant=training → TrainingHandler antes del
 * Router": este claim solo se evalúa cuando `DomainFallbackResolver` ya
 * confirmó que el Router agotó sus 4 Tiers reales (Training explícito,
 * Payment, Referral, CustomerCare, contextual, FAQ) sin que ninguno
 * reconociera el mensaje — Training es aquí el FALLBACK del dominio,
 * nunca el router universal del tenant. Cualquier intent específico
 * (Payment/CustomerCare/FAQ/Training explícito o contextual) sigue
 * ganando siempre, sin ningún cambio de comportamiento.
 *
 * Lee ÚNICAMENTE `$context->tenant->primary_domain` — el Tenant ya viene
 * cargado en `ExecutionContext` (ver su docblock), así que esta
 * verificación no ejecuta ninguna consulta adicional.
 */
class TrainingDomainFallbackClaim implements DomainFallbackClaimInterface
{
    public function claim(ExecutionContext $context): ?Intent
    {
        return $context->tenant->primary_domain === 'training' ? Intent::Training : null;
    }
}
