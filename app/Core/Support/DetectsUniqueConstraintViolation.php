<?php

namespace App\Core\Support;

use Illuminate\Database\QueryException;

/**
 * Promovido desde App\Referrals\Support (Hito 13) a App\Core\Support
 * (P1-B): inspeccionado y confirmado genérico — solo detecta SQLSTATE 23000
 * sobre una QueryException, sin ningún conocimiento de Referrals ni de
 * ningún otro dominio. "No confiar solo en `if (!exists())`": cualquier
 * garantía de first-touch/idempotencia respaldada por un índice único de
 * base de datos (referrals.referred_contact_id, contact_acquisitions.contact_id,
 * referral_rewards.referral_id/payment_id) necesita esta misma detección
 * ante una carrera real — mismo criterio que el dedup de WAMID vía
 * `Cache::add()` atómico. Un único punto evita duplicar la detección del
 * mismo SQLSTATE en cada dominio que la necesite.
 */
trait DetectsUniqueConstraintViolation
{
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000';
    }
}
