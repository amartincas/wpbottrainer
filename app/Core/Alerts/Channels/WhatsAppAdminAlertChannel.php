<?php

namespace App\Core\Alerts\Channels;

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertChannelInterface;
use App\Core\Alerts\AlertSeverity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;

/**
 * Entrega por WhatsApp a los superadmins de la plataforma. Deliberadamente
 * sin ningún conocimiento de Safety/Payments/etc — solo sabe leer una Alert
 * genérica y decidir si puede entregarla, nunca por qué existe.
 *
 * Emisor / destinatario (decisión explícita, ver docs/DECISIONS.md):
 * - Emisor: el número de WhatsApp Business del Tenant que originó la alerta
 *   (`Alert::context['tenant_id']`) — reutiliza la infraestructura Meta ya
 *   configurada por tenant, sin crear un número de "operaciones" dedicado.
 *   Una Alert sin tenant_id resoluble (ej. un futuro error de Queue/
 *   Infraestructura sin tenant asociado) NO se entrega por este canal —
 *   solo queda en PersistedAlertChannel. Límite conocido, documentado.
 * - Destinatario: TODOS los `User` con `is_super_admin = true` y `phone`
 *   configurado — is_super_admin es un rol GLOBAL de plataforma (confirmado
 *   por auditoría: no depende del tenant_id de ese User), así que no se
 *   filtra por tenant.
 *
 * Throttling básico (punto 5 del alcance aprobado — NO deduplicación
 * global): una Alert con la misma categoría+severidad+mensaje se ignora si
 * ya se envió una idéntica en los últimos 5 minutos. Es intencionalmente
 * simple (una entrada de cache con TTL, sin persistencia propia) — evita
 * tormentas de alertas repetidas, no pretende ser un sistema de
 * deduplicación completo.
 */
class WhatsAppAdminAlertChannel implements AlertChannelInterface
{
    private const THROTTLE_MINUTES = 5;

    public function supports(Alert $alert): bool
    {
        if (! in_array($alert->severity, [AlertSeverity::Warning, AlertSeverity::Critical], true)) {
            return false;
        }

        if ($this->resolveTenant($alert) === null) {
            return false;
        }

        return $this->resolveRecipientPhones()->isNotEmpty();
    }

    public function deliver(Alert $alert): void
    {
        if (! Cache::add($this->throttleKey($alert), true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        $tenant = $this->resolveTenant($alert);
        $text = $this->buildMessage($alert, $tenant);

        foreach ($this->resolveRecipientPhones() as $phone) {
            WhatsAppService::sendMessage($phone, $text, $tenant);
        }
    }

    private function resolveTenant(Alert $alert): ?Tenant
    {
        $tenantId = $alert->context['tenant_id'] ?? null;

        if ($tenantId === null) {
            return null;
        }

        return Tenant::find($tenantId);
    }

    private function resolveRecipientPhones(): \Illuminate\Support\Collection
    {
        return User::where('is_super_admin', true)
            ->whereNotNull('phone')
            ->pluck('phone');
    }

    private function buildMessage(Alert $alert, Tenant $tenant): string
    {
        $lines = [
            "⚠️ Alerta [{$alert->severity->value}] · {$alert->category}",
            $alert->message,
            "Tenant: {$tenant->name} (#{$tenant->id})",
        ];

        return implode("\n", $lines);
    }

    private function throttleKey(Alert $alert): string
    {
        return 'alert-throttle:'.md5($alert->category.'|'.$alert->severity->value.'|'.$alert->message);
    }
}
