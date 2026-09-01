<?php

namespace App\Core\Alerts\Channels;

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertChannelInterface;
use App\Models\AlertLog;

/**
 * El registro durable — siempre corre, para toda Alert que pase por
 * AlertService, sin importar severidad ni dominio. No es un log general de
 * la aplicación (eso sigue siendo storage/logs): solo lo que un dominio
 * explícitamente decidió que era una alerta operativa importante.
 *
 * `delivery_status = 'recorded'` significa únicamente "esta fila se
 * persistió correctamente" — este canal no sabe si algún otro canal (ej.
 * WhatsAppAdminAlertChannel) logró entregar la alerta a un humano; no hay
 * coordinación entre canales por diseño (cada uno es independiente).
 */
class PersistedAlertChannel implements AlertChannelInterface
{
    public function supports(Alert $alert): bool
    {
        return true;
    }

    public function deliver(Alert $alert): void
    {
        AlertLog::create([
            'category' => $alert->category,
            'severity' => $alert->severity->value,
            'message' => $alert->message,
            'context' => $alert->context,
            'delivery_status' => 'recorded',
        ]);
    }
}
