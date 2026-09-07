<?php

namespace App\Training\Support;

use App\Models\Contact;

/**
 * Hito 10 — única puerta de entrada para obtener la zona horaria efectiva
 * de un Contact. Ningún otro consumidor (`ReminderTimeResolver`,
 * formateo de confirmaciones) debe leer `Tenant.timezone` directamente.
 *
 * `America/Bogota` NO aparece en esta clase — es el valor inicial de
 * producto (columna/formulario), nunca un fallback de esta resolución. Un
 * Tenant mal configurado hace que esto falle ruidosamente (ver
 * `InvalidTenantTimezoneException`), nunca que un Reminder se calcule
 * silenciosamente en la hora de otro país.
 *
 * Seam explícito para `Contact.timezone` (futuro, NO creado en este hito):
 * cuando exista, esta es la única línea que cambiará en todo el sistema.
 */
class TimezoneResolver
{
    public function resolve(Contact $contact): string
    {
        $timezone = $contact->tenant?->timezone;

        if ($timezone === null || ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidTenantTimezoneException($contact->tenant_id, $timezone);
        }

        return $timezone;
    }
}
