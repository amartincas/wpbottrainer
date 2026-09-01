<?php

namespace App\Core\Alerts;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Fan-out a todos los canales registrados que "soportan" la Alert — mismo
 * patrón Container-resuelto de Router/Dispatcher/ContextBuilder/
 * PreRoutingScreener, pero sin detenerse en el primero: varios canales
 * pueden querer la misma Alert.
 *
 * Garantía central (Hito 7.1, explícitamente pedida): un fallo de
 * ENTREGA de una alerta NUNCA debe propagarse al proceso que la originó
 * (ej. Safety no debe dejar de bloquear porque WhatsApp falló). Cada canal
 * se invoca dentro de su propio try/catch — un canal roto no afecta a los
 * demás ni al llamador.
 */
class AlertService
{
    /**
     * @param array<int, class-string<AlertChannelInterface>> $channelClasses
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $channelClasses,
    ) {}

    public function send(Alert $alert): void
    {
        foreach ($this->channelClasses as $channelClass) {
            /** @var AlertChannelInterface $channel */
            $channel = $this->container->make($channelClass);

            try {
                if (! $channel->supports($alert)) {
                    continue;
                }

                $channel->deliver($alert);
            } catch (\Throwable $e) {
                Log::error('ALERT_CHANNEL_DELIVERY_FAILED', [
                    'channel' => $channelClass,
                    'category' => $alert->category,
                    'severity' => $alert->severity->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
