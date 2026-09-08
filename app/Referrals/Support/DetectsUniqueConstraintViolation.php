<?php

namespace App\Referrals\Support;

use Illuminate\Database\QueryException;

/**
 * Hito 13 — "no confiar solo en `if (!exists())`": tanto la atribución
 * (índice único en `referrals.referred_contact_id`) como la recompensa
 * (índices únicos en `referral_rewards.referral_id`/`payment_id`) dejan
 * que la base de datos sea la autoridad final ante concurrencia real —
 * mismo criterio que el dedup de WAMID vía `Cache::add()` atómico. Esta
 * pequeña utilidad evita duplicar la misma detección de SQLSTATE 23000 en
 * los dos puntos que la necesitan.
 */
trait DetectsUniqueConstraintViolation
{
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000';
    }
}
