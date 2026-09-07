<?php

namespace App\Training\Support;

/**
 * Hito 10 — lanzada por `TimezoneResolver` cuando `Tenant.timezone` es nulo
 * o no es un identificador IANA válido. Este caso NO debería alcanzarse en
 * operación normal (la columna es `NOT NULL` y el modelo valida al
 * guardar), pero una fila legada o un dato corrupto no debe hacer que un
 * `Reminder` se calcule silenciosamente en una zona horaria equivocada —
 * mejor fallar ruidosamente que enviar un recordatorio a la hora incorrecta.
 */
class InvalidTenantTimezoneException extends \RuntimeException
{
    public function __construct(public readonly int $tenantId, public readonly ?string $timezone)
    {
        parent::__construct("Invalid or missing IANA timezone for Tenant #{$tenantId}: ".($timezone ?? 'null'));
    }
}
