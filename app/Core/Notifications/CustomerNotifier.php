<?php

namespace App\Core\Notifications;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use Illuminate\Support\Facades\Log;

/**
 * Infraestructura transversal (Hito 8, ajuste) para mensajes salientes
 * INICIADOS POR EL SISTEMA hacia un cliente — transaccional, no
 * administrativo. Distinto y separado de App\Core\Alerts\AlertService, que
 * es exclusivamente para operadores/superadmins; este componente nunca debe
 * fusionarse con aquel.
 *
 * Decide ÚNICAMENTE el mecanismo de entrega — mensaje libre si la ventana de
 * 24h de WhatsApp sigue abierta, WhatsApp Template si no — nunca CUÁNDO ni
 * POR QUÉ contactar al cliente; esa decisión es y seguirá siendo exclusiva
 * del dominio que llama (Payments; Reminders desde Hito 10 — este
 * componente sigue sin contener ninguna de sus reglas).
 *
 * Un dominio (Payments/Safety/Reminders) solo describe el evento — nunca
 * conoce Graph API, nombre técnico de plantilla, versión de Meta, ni la
 * regla de ventana. Ver docs/DECISIONS.md.
 *
 * `eventKey` es un string libre a propósito (mismo criterio que
 * `Alert::category` en App\Core\Alerts) — un enum cerrado aquí obligaría a
 * tocar este componente cada vez que un dominio nuevo necesite notificar.
 *
 * Ninguna excepción se propaga fuera de `notify()`: un fallo de entrega
 * (plantilla no configurada, Meta la rechaza, error de red) nunca debe
 * afectar al proceso que originó la notificación — mismo contrato que
 * AlertService::send(). Si no hay plantilla configurada, NO se persiste
 * ningún WhatsAppMessage — nunca se intentó nada real (comportamiento
 * original, sin cambios).
 *
 * Hito 10 — idempotencia (`$idempotencyKey` opcional): garantiza "como
 * máximo una entrega CONFIRMADA" por clave lógica, nunca "exactly once"
 * (ver docs/DECISIONS.md). `WhatsAppMessage.idempotency_key` (índice único)
 * es la identidad; `WhatsAppMessage.dispatch_confirmed_at` es la única
 * fuente DURABLE (no caché) de "esto sí se confirmó con Meta" — se fija
 * únicamente cuando la llamada real responde con éxito. Si ya existe un
 * `WhatsAppMessage` con esa clave y `dispatch_confirmed_at` ya tiene valor,
 * NUNCA se reintenta el envío. Si existe pero sin confirmar (resultado de
 * un intento anterior desconocido — el proceso pudo morir antes de que
 * Meta respondiera), se reintenta REUSANDO la MISMA fila (`firstOrCreate`),
 * nunca creando una segunda — el contenido puede regenerarse en el retry
 * sin que eso afecte la identidad lógica del envío ni permita una segunda
 * entrega confirmada.
 */
class CustomerNotifier
{
    /**
     * Margen conservador sobre las 24h reales de Meta (aprobado
     * explícitamente): a partir de este umbral se asume la ventana cerrada
     * y se usa plantilla, para no arriesgar un intento de mensaje libre que
     * Meta rechazaría.
     */
    private const WINDOW_THRESHOLD_MINUTES = (23 * 60) + 30;

    public function notify(
        Tenant $tenant,
        string $to,
        string $eventKey,
        array $variables,
        string $freeFormText,
        ?string $idempotencyKey = null,
    ): CustomerNotifyResult {
        try {
            if ($idempotencyKey !== null) {
                $existing = WhatsAppMessage::where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null && $existing->dispatch_confirmed_at !== null) {
                    // Ya hubo una entrega CONFIRMADA para esta MISMA
                    // ocurrencia (mismo Reminder + mismo fire_at) — nunca se
                    // reenvía, sin importar cuántas veces se reinvoque con
                    // la misma clave.
                    Log::info('CUSTOMER_NOTIFIER_ALREADY_CONFIRMED', [
                        'tenant_id' => $tenant->id,
                        'event_key' => $eventKey,
                        'idempotency_key' => $idempotencyKey,
                    ]);

                    return CustomerNotifyResult::confirmed();
                }
            }

            $wamid = $this->isWindowOpen($tenant, $to)
                ? $this->sendFreeForm($tenant, $to, $eventKey, $freeFormText, $idempotencyKey)
                : $this->sendViaTemplate($tenant, $to, $eventKey, $variables, $freeFormText, $idempotencyKey);

            return $wamid !== null ? CustomerNotifyResult::confirmed() : CustomerNotifyResult::unconfirmed();
        } catch (\Throwable $e) {
            Log::error('CUSTOMER_NOTIFIER_FAILED', [
                'tenant_id' => $tenant->id,
                'event_key' => $eventKey,
                'idempotency_key' => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);

            return CustomerNotifyResult::unconfirmed();
        }
    }

    /**
     * Señal ya existente en el proyecto (Conversation.last_session_at, HD),
     * actualizada únicamente en mensajes ENTRANTES del cliente
     * (WhatsAppController::handle()) — nunca en salientes. No se consulta
     * ninguna API de Meta para esto (aprobado explícitamente).
     */
    private function isWindowOpen(Tenant $tenant, string $to): bool
    {
        $conversation = Conversation::where('tenant_id', $tenant->id)
            ->where('customer_phone', $to)
            ->first();

        if ($conversation === null || $conversation->last_session_at === null) {
            return false;
        }

        return $conversation->last_session_at->diffInMinutes(now()) < self::WINDOW_THRESHOLD_MINUTES;
    }

    private function sendFreeForm(Tenant $tenant, string $to, string $eventKey, string $text, ?string $idempotencyKey): ?string
    {
        $message = $this->persistOutbound($tenant, $to, $text, $idempotencyKey);

        $wamid = WhatsAppService::sendMessage($to, $text, $tenant);

        $this->finalizeDelivery($message, $wamid, $eventKey, 'free_form', $idempotencyKey);

        return $wamid;
    }

    private function sendViaTemplate(Tenant $tenant, string $to, string $eventKey, array $variables, string $freeFormText, ?string $idempotencyKey): ?string
    {
        $template = WhatsAppTemplate::where('tenant_id', $tenant->id)
            ->where('event_key', $eventKey)
            ->first();

        if ($template === null) {
            // Nunca se persiste nada aquí — no se intentó ningún envío real.
            Log::warning('CUSTOMER_NOTIFIER_TEMPLATE_NOT_CONFIGURED', [
                'tenant_id' => $tenant->id,
                'event_key' => $eventKey,
            ]);

            return null;
        }

        $orderedVariables = $this->resolveVariables($template, $variables);

        // Se persiste el texto libre equivalente (no la plantilla cruda con
        // {{N}}) — mantiene el historial legible en WhatsAppChatCenter/el
        // contexto de FallbackChatHandler, igual que si la ventana hubiera
        // estado abierta. El canal técnico realmente usado queda en el log
        // CUSTOMER_NOTIFIER_SENT, no en WhatsAppMessage.
        $message = $this->persistOutbound($tenant, $to, $freeFormText, $idempotencyKey);

        $wamid = WhatsAppService::sendTemplateMessage($to, $template->name, $template->language, $orderedVariables, $tenant);

        $this->finalizeDelivery($message, $wamid, $eventKey, 'template', $idempotencyKey, $template->name);

        return $wamid;
    }

    /**
     * `firstOrCreate` sobre `idempotency_key` cuando se provee: si ya
     * existe una fila con esa clave (confirmada o no), se REUTILIZA — nunca
     * se crea una segunda. Sin clave (llamadores existentes, Payments),
     * comportamiento idéntico al de siempre.
     */
    private function persistOutbound(Tenant $tenant, string $to, string $text, ?string $idempotencyKey): WhatsAppMessage
    {
        if ($idempotencyKey !== null) {
            return WhatsAppMessage::firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                ['tenant_id' => $tenant->id, 'customer_phone' => $to, 'role' => 'assistant', 'content' => $text],
            );
        }

        return WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $to,
            'role' => 'assistant',
            'content' => $text,
        ]);
    }

    private function finalizeDelivery(WhatsAppMessage $message, ?string $wamid, string $eventKey, string $channel, ?string $idempotencyKey, ?string $templateName = null): void
    {
        if ($wamid === null) {
            Log::warning('CUSTOMER_NOTIFIER_META_SEND_FAILED', [
                'whatsapp_message_id' => $message->id,
                'event_key' => $eventKey,
                'channel' => $channel,
            ]);

            Log::info('CUSTOMER_NOTIFIER_SENT', array_filter([
                'tenant_id' => $message->tenant_id, 'event_key' => $eventKey, 'channel' => $channel,
                'template_name' => $templateName, 'success' => false,
            ], fn ($v) => $v !== null));

            return;
        }

        if ($idempotencyKey !== null) {
            // Se fija SOLO ahora, justo después de que Meta confirmó — es
            // la única fuente durable de "esto sí salió" (nunca la caché de
            // WhatsAppStatusTracker, que no es duradera).
            $message->update(['dispatch_confirmed_at' => now()]);
        }

        WhatsAppStatusTracker::trackMessage($message->id, $wamid);

        Log::info('CUSTOMER_NOTIFIER_SENT', array_filter([
            'tenant_id' => $message->tenant_id, 'event_key' => $eventKey, 'channel' => $channel,
            'template_name' => $templateName, 'success' => true,
        ], fn ($v) => $v !== null));
    }

    /**
     * `parameters_map` ya existe (WhatsAppTemplateResource): clave = posición
     * del marcador {{N}}, valor = nombre de variable. Aquí el valor se busca
     * únicamente en las `variables` que el dominio ya provee — este
     * componente no conoce Contact ni ningún otro modelo de dominio.
     *
     * @return array<int, string>
     */
    private function resolveVariables(WhatsAppTemplate $template, array $variables): array
    {
        $map = $template->parameters_map ?? [];
        $resolved = [];

        foreach ($map as $position => $variableName) {
            $resolved[(int) $position] = (string) ($variables[$variableName] ?? '');
        }

        ksort($resolved);

        return array_values($resolved);
    }
}
