<?php

namespace App\Referrals\Handlers;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\HandlerInterface;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Referrals\Models\ReferralCode;
use App\Referrals\Support\ReferralCodeGenerator;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use Illuminate\Support\Facades\Log;

/**
 * El único Handler de Referrals (Hito 13) — comandos conversacionales del
 * REFERENTE: obtener su invitación, consultar sus referidos. La
 * ATRIBUCIÓN (reconocer un código en el primer mensaje de un invitado) NO
 * vive aquí — corre antes del Router, en
 * App\Referrals\Support\ReferralAttributionPreRoutingScreen.
 *
 * Cualquier Contact puede pedir su código, sin importar su estado de
 * TrainingAccess (Active/Trial/Free/Expired/Revoked/sin acceso en
 * absoluto) — no hay ninguna restricción de elegibilidad para SER
 * referente, solo para SER referido (ver ReferralAttributionPreRoutingScreen).
 */
class ReferralHandler implements HandlerInterface
{
    private const PROGRAM_DISABLED_MESSAGE = 'El programa de referidos no está disponible por ahora. Vuelve a intentarlo más adelante.';

    public function __construct(
        private readonly ReferralCodeGenerator $codeGenerator,
    ) {}

    public function handle(ExecutionContext $context): void
    {
        $tenant = $context->tenant;
        $from = $context->message->from;
        $rawBody = $context->message->messageBody ?? '';

        $contact = Contact::firstOrCreate(
            ['tenant_id' => $tenant->id, 'customer_phone' => $from],
            ['summary' => 'Registro de Referrals', 'bot_active' => true],
        );

        $this->logInbound($tenant, $from, $rawBody);

        if (! $tenant->referral_program_enabled) {
            $this->reply($from, self::PROGRAM_DISABLED_MESSAGE, $tenant);

            return;
        }

        if ($this->looksLikeStatsInquiry($rawBody)) {
            $this->handleStats($contact, $from, $tenant);

            return;
        }

        $this->handleGetInvitation($contact, $from, $tenant);
    }

    private const STATS_KEYWORDS = ['mis referidos', 'cuántos referidos', 'cuantos referidos', 'cuántos he referido', 'cuantos he referido'];

    private function looksLikeStatsInquiry(string $body): bool
    {
        $normalized = mb_strtolower($body);

        foreach (self::STATS_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mejora UX (ver docs/DECISIONS.md): botón nativo, texto corto y
     * seguro contra el límite de 20 caracteres de Meta (verificado con
     * mb_strlen — el emoji original ("👉 Invitar a un amigo") da exactamente
     * 20 codepoints, en el límite; se prefiere esta variante sin emoji para
     * no depender de cómo Meta cuente internamente un carácter compuesto).
     */
    private const CTA_BUTTON_TEXT = 'Invitar a un amigo';

    private function handleGetInvitation(Contact $contact, string $from, Tenant $tenant): void
    {
        $code = ReferralCode::firstOrCreate(
            ['contact_id' => $contact->id],
            ['code' => $this->codeGenerator->generateUnique()],
        );

        $invitationText = "Hola! Quiero unirme a {$tenant->name} 💪 {$code->code}";

        if (! empty($tenant->wa_display_phone_number)) {
            // Mejora UX (ver docs/DECISIONS.md) — dos mensajes distintos,
            // no confundir:
            //   Mensaje 2 ($invitationText/$botLink): técnico, lo recibe el
            //     BOT una vez B/C confirman. Contiene el código.
            //   Mensaje 1 ($shareableMessage): humano, lo que A realmente
            //     COMPARTE al elegir destinatarios — WhatsApp reenvía texto
            //     plano, sin ningún botón (los botones interactivos nunca
            //     sobreviven un reenvío, limitación de la plataforma). Debe
            //     ser autosuficiente: quién invita, qué es el tenant, y un
            //     enlace DIRECTO al bot (con número, a diferencia del CTA de
            //     A) para que B/C solo tengan que pulsar Enviar.
            //
            // wa_display_phone_number sigue siendo la señal que decide si
            // se muestra el CTA. La URL del CTA de A (Click to Chat SIN
            // destinatario, hallazgo real de prueba manual — ver
            // docs/DECISIONS.md) sigue sin número — ahora apunta al Mensaje
            // 1, no al Mensaje 2.
            $botLink = 'https://wa.me/'.$tenant->wa_display_phone_number.'?text='.rawurlencode($invitationText);
            $shareableMessage = $this->buildShareableInvitationMessage($contact, $tenant, $botLink);
            $ctaUrl = 'https://wa.me/?text='.rawurlencode($shareableMessage);
            $this->replyWithInvitationCta($from, $tenant, $code->code, $ctaUrl);
        } else {
            $lines = ['🎁 Aquí está tu invitación — compártela con tus amigos:'];
            $lines[] = 'Diles que escriban este mensaje cuando nos escriban:';
            $lines[] = '';
            $lines[] = '"'.$invitationText.'"';
            $lines[] = '';
            $lines[] = 'O simplemente el código:';
            $lines[] = $code->code;
            $lines[] = '';
            $lines[] = 'Cuando activen su membresía, ganas días adicionales de entrenamiento. 💪';

            $this->reply($from, implode("\n", $lines), $tenant);
        }

        Log::info('REFERRAL_INVITATION_SENT', ['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'code' => $code->code]);
    }

    /**
     * Mensaje 1 (ver docs/DECISIONS.md) — el contenido que A efectivamente
     * COMPARTE con B/C al tocar el CTA y elegir destinatarios. Nombre del
     * referente con fallback determinista si `customer_name` no está
     * disponible (no está garantizado — ver docblock de la clase: cualquier
     * Contact puede pedir su código sin haber pasado por onboarding).
     */
    private function buildShareableInvitationMessage(Contact $contact, Tenant $tenant, string $botLink): string
    {
        $referrerName = trim((string) $contact->customer_name);

        $intro = $referrerName !== ''
            ? "Hola! {$referrerName} quiere invitarte a {$tenant->name}, tu entrenador de ejercicios personalizado 💪"
            : "Hola! Te están invitando a {$tenant->name}, tu entrenador de ejercicios personalizado 💪";

        return "{$intro}\n\n👉 Comienza aquí: {$botLink}";
    }

    /**
     * Rama CTA URL (Meta interactive, no Template) de handleGetInvitation()
     * — usada únicamente cuando el tenant tiene wa_display_phone_number
     * configurado. Mismo patrón que reply(): persiste el WhatsAppMessage
     * ANTES de enviar (historial fuente de verdad, no depende de que el
     * envío real tenga éxito), envía, y trackea el WAMID con el mismo
     * WhatsAppStatusTracker ya usado en todo el proyecto.
     *
     * El historial (WhatsAppMessage.content) solo admite texto — no existe
     * ninguna columna para el payload interactivo estructurado, y no se
     * agrega ninguna en este cambio. Se guarda una representación textual
     * legible del cuerpo + botón, suficiente para auditar la conversación,
     * nunca el JSON crudo enviado a Meta.
     */
    private function replyWithInvitationCta(string $from, Tenant $tenant, string $code, string $invitationUrl): void
    {
        $bodyText = "🎁 Aquí está tu invitación:\n\n"
            .'Comparte este botón con tu amigo — podrás elegir a quién enviársela.'."\n\n"
            ."Código de referido: {$code}\n\n"
            .'Cuando activen su membresía, ganas días adicionales de entrenamiento. 💪';

        $message = WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $from,
            'role' => 'assistant',
            'content' => $bodyText."\n\n[".self::CTA_BUTTON_TEXT."] -> {$invitationUrl}",
        ]);

        $wamid = WhatsAppService::sendCtaUrlMessage($from, $bodyText, self::CTA_BUTTON_TEXT, $invitationUrl, $tenant);

        if ($wamid) {
            WhatsAppStatusTracker::trackMessage($message->id, $wamid);
        } else {
            Log::warning('REFERRAL_META_SEND_FAILED', [
                'whatsapp_message_id' => $message->id,
                'tenant_id' => $tenant->id,
                'customer_phone' => $from,
            ]);
        }
    }

    private function handleStats(Contact $contact, string $from, Tenant $tenant): void
    {
        $referrals = $contact->referralsMade()->with('reward')->get();
        $totalReferred = $referrals->count();
        $totalRewarded = $referrals->filter(fn ($referral) => $referral->reward !== null)->count();
        $totalDays = $referrals->sum(fn ($referral) => $referral->reward?->reward_days ?? 0);

        if ($totalReferred === 0) {
            $this->reply($from, 'Todavía no has referido a nadie. Escríbeme "mi código" para obtener tu invitación. 🎁', $tenant);

            return;
        }

        $message = "Has referido a {$totalReferred} ".($totalReferred === 1 ? 'persona' : 'personas').
            " y {$totalRewarded} ya ".($totalRewarded === 1 ? 'activó' : 'activaron')." su membresía. ".
            "Has ganado {$totalDays} ".($totalDays === 1 ? 'día' : 'días').". 💪";

        $this->reply($from, $message, $tenant);
    }

    private function logInbound(Tenant $tenant, string $from, string $rawBody): void
    {
        WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $from,
            'role' => 'user',
            'content' => $rawBody,
        ]);
    }

    private function reply(string $from, string $text, Tenant $tenant): void
    {
        $message = WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $from,
            'role' => 'assistant',
            'content' => $text,
        ]);

        $wamid = WhatsAppService::sendMessage($from, $text, $tenant);

        if ($wamid) {
            WhatsAppStatusTracker::trackMessage($message->id, $wamid);
        } else {
            Log::warning('REFERRAL_META_SEND_FAILED', [
                'whatsapp_message_id' => $message->id,
                'tenant_id' => $tenant->id,
                'customer_phone' => $from,
            ]);
        }
    }
}
