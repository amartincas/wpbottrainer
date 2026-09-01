<?php

namespace App\Core\Alerts;

/**
 * Contract for a single delivery channel AlertService can fan-out to.
 *
 * Unlike IntentClassifierInterface/PreRoutingScreenInterface (donde el
 * primer resultado no-nulo gana), AQUÍ pueden aplicar varios canales al
 * mismo tiempo — un alert de Payments podría ir simultáneamente a WhatsApp
 * Y quedar persistido. `supports()` decide si a este canal le corresponde
 * (por severidad, por si hay un destinatario resoluble, etc.) — nunca por
 * conocimiento de un dominio concreto (Safety/Payments); eso vive en cómo
 * el dominio construye la Alert (category/severity/message), nunca en el
 * canal.
 */
interface AlertChannelInterface
{
    public function supports(Alert $alert): bool;

    public function deliver(Alert $alert): void;
}
