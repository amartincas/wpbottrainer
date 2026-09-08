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

    private function handleGetInvitation(Contact $contact, string $from, Tenant $tenant): void
    {
        $code = ReferralCode::firstOrCreate(
            ['contact_id' => $contact->id],
            ['code' => $this->codeGenerator->generateUnique()],
        );

        $invitationText = "Hola! Quiero unirme a {$tenant->name} 💪 {$code->code}";

        $lines = ['🎁 Aquí está tu invitación — compártela con tus amigos:'];

        if (! empty($tenant->wa_display_phone_number)) {
            $link = 'https://wa.me/'.$tenant->wa_display_phone_number.'?text='.rawurlencode($invitationText);
            $lines[] = $link;
            $lines[] = '';
            $lines[] = 'O si prefieres, diles que escriban este código cuando nos escriban:';
        } else {
            $lines[] = 'Diles que escriban este mensaje cuando nos escriban:';
            $lines[] = '';
            $lines[] = '"'.$invitationText.'"';
            $lines[] = '';
            $lines[] = 'O simplemente el código:';
        }

        $lines[] = $code->code;
        $lines[] = '';
        $lines[] = 'Cuando activen su membresía, ganas días adicionales de entrenamiento. 💪';

        $this->reply($from, implode("\n", $lines), $tenant);

        Log::info('REFERRAL_INVITATION_SENT', ['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'code' => $code->code]);
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
