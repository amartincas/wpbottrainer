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
 * del dominio que llama (Payments hoy; Proactivity en el futuro reutiliza
 * este mismo método sin que este componente contenga ninguna de sus reglas).
 *
 * Un dominio (Payments/Safety/futuros) solo describe el evento — nunca
 * conoce Graph API, nombre técnico de plantilla, versión de Meta, ni la
 * regla de ventana. Ver docs/DECISIONS.md.
 *
 * `eventKey` es un string libre a propósito (mismo criterio que
 * `Alert::category` en App\Core\Alerts) — un enum cerrado aquí obligaría a
 * tocar este componente cada vez que un dominio nuevo necesite notificar.
 * Los valores válidos hoy (payment_confirmed, payment_rejected) solo existen
 * como guía en el Select de WhatsAppTemplateForm, no como restricción de
 * este componente ni de la base de datos.
 *
 * Ninguna excepción se propaga fuera de `notify()`: un fallo de entrega
 * (plantilla no configurada, Meta la rechaza, error de red) nunca debe
 * afectar al proceso que originó la notificación — mismo contrato que
 * AlertService::send().
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

    public function notify(Tenant $tenant, string $to, string $eventKey, array $variables, string $freeFormText): void
    {
        try {
            if ($this->isWindowOpen($tenant, $to)) {
                $this->sendFreeForm($tenant, $to, $eventKey, $freeFormText);

                return;
            }

            $this->sendViaTemplate($tenant, $to, $eventKey, $variables, $freeFormText);
        } catch (\Throwable $e) {
            Log::error('CUSTOMER_NOTIFIER_FAILED', [
                'tenant_id' => $tenant->id,
                'event_key' => $eventKey,
                'error' => $e->getMessage(),
            ]);
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

    private function sendFreeForm(Tenant $tenant, string $to, string $eventKey, string $text): void
    {
        $message = $this->persistOutbound($tenant, $to, $text);

        $wamid = WhatsAppService::sendMessage($to, $text, $tenant);

        $this->trackDelivery($message, $wamid, $eventKey, 'free_form');
    }

    private function sendViaTemplate(Tenant $tenant, string $to, string $eventKey, array $variables, string $freeFormText): void
    {
        $template = WhatsAppTemplate::where('tenant_id', $tenant->id)
            ->where('event_key', $eventKey)
            ->first();

        if ($template === null) {
            Log::warning('CUSTOMER_NOTIFIER_TEMPLATE_NOT_CONFIGURED', [
                'tenant_id' => $tenant->id,
                'event_key' => $eventKey,
            ]);

            return;
        }

        $orderedVariables = $this->resolveVariables($template, $variables);

        // Se persiste el texto libre equivalente (no la plantilla cruda con
        // {{N}}) — mantiene el historial legible en WhatsAppChatCenter/el
        // contexto de FallbackChatHandler, igual que si la ventana hubiera
        // estado abierta. El canal técnico realmente usado queda en el log
        // CUSTOMER_NOTIFIER_SENT, no en WhatsAppMessage.
        $message = $this->persistOutbound($tenant, $to, $freeFormText);

        $wamid = WhatsAppService::sendTemplateMessage($to, $template->name, $template->language, $orderedVariables, $tenant);

        $this->trackDelivery($message, $wamid, $eventKey, 'template', $template->name);
    }

    /**
     * Mismo patrón ya usado por PaymentHandler::reply()/TrainingHandler::reply()
     * — el historial de WhatsAppMessage debe quedar consistente sin importar
     * qué componente originó el mensaje saliente. Se persiste ANTES de
     * intentar el envío real (igual que esos dos) para que quede registro de
     * lo que se intentó comunicar incluso si Meta rechaza el envío.
     */
    private function persistOutbound(Tenant $tenant, string $to, string $text): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $to,
            'role' => 'assistant',
            'content' => $text,
        ]);
    }

    private function trackDelivery(WhatsAppMessage $message, ?string $wamid, string $eventKey, string $channel, ?string $templateName = null): void
    {
        if ($wamid !== null) {
            WhatsAppStatusTracker::trackMessage($message->id, $wamid);
        } else {
            Log::warning('CUSTOMER_NOTIFIER_META_SEND_FAILED', [
                'whatsapp_message_id' => $message->id,
                'event_key' => $eventKey,
                'channel' => $channel,
            ]);
        }

        Log::info('CUSTOMER_NOTIFIER_SENT', array_filter([
            'tenant_id' => $message->tenant_id,
            'event_key' => $eventKey,
            'channel' => $channel,
            'template_name' => $templateName,
            'success' => $wamid !== null,
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
