<?php

namespace App\Referrals\Support;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\PreRoutingScreenInterface;
use App\Models\Contact;
use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use App\Referrals\Models\Referral;
use App\Referrals\Models\ReferralCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hito 13 — captura de atribución: corre para TODO mensaje, ANTES de
 * cualquier clasificación de Intent (mismo mecanismo que
 * SafetySignalPreRoutingScreen, Hito 7) — es el único punto del pipeline
 * garantizado de ver el mensaje sin importar a qué Handler termine yendo
 * (un usuario nuevo puede caer en TrainingHandler, PaymentHandler o
 * FallbackChatHandler dependiendo de su primer mensaje — ver
 * docs/DECISIONS.md).
 *
 * A diferencia de SafetySignalPreRoutingScreen, este screen NUNCA reclama
 * el pipeline — `screen()` siempre retorna `false`. Es puramente un efecto
 * secundario (crear una atribución si corresponde) que nunca desvía al
 * usuario del flujo normal de onboarding/pagos/chat.
 *
 * Reglas evaluadas en orden, todas deterministas (sin IA), cualquiera de
 * ellas detiene el proceso como un no-op silencioso (solo `Log::info`,
 * nunca una fila persistida):
 *   1. programa desactivado para el Tenant
 *   2. sin código reconocible en el texto (fast-path, sin ninguna query)
 *   3. código inexistente
 *   4. cross-tenant (el código pertenece a un Contact de otro Tenant)
 *   5. self-referral (el remitente es el mismo Contact del código)
 *   6. el Contact ya tiene una atribución previa (la primera gana, nunca
 *      se reemplaza — ver App\Referrals\Models\Referral)
 *   7. el Contact ya tuvo al menos un Payment `confirmed` antes (nunca
 *      "Contact no existe" como criterio — Trial/Free/rejected/expired/
 *      pending/under_review NO bloquean, ver docs/DECISIONS.md)
 */
class ReferralAttributionPreRoutingScreen implements PreRoutingScreenInterface
{
    use DetectsUniqueConstraintViolation;

    public function __construct(
        private readonly ReferralCodeGenerator $codeGenerator,
    ) {}

    public function screen(ExecutionContext $context): bool
    {
        $tenant = $context->tenant;

        if (! $tenant->referral_program_enabled) {
            return false;
        }

        $body = $context->message->messageBody ?? '';
        $code = $this->codeGenerator->extractFromText($body);

        if ($code === null) {
            return false;
        }

        $referralCode = ReferralCode::where('code', $code)->first();

        if ($referralCode === null) {
            Log::info('REFERRAL_ATTRIBUTION_CODE_NOT_FOUND', ['tenant_id' => $tenant->id, 'code' => $code]);

            return false;
        }

        $referrerContact = $referralCode->contact;

        if ($referrerContact->tenant_id !== $tenant->id) {
            Log::info('REFERRAL_ATTRIBUTION_CROSS_TENANT_BLOCKED', [
                'tenant_id' => $tenant->id, 'code' => $code, 'referrer_tenant_id' => $referrerContact->tenant_id,
            ]);

            return false;
        }

        $invitedContact = Contact::firstOrCreate(
            ['tenant_id' => $tenant->id, 'customer_phone' => $context->message->from],
            ['summary' => 'Registro de Referrals (atribución)', 'bot_active' => true],
        );

        if ($invitedContact->id === $referrerContact->id) {
            Log::info('REFERRAL_ATTRIBUTION_SELF_REFERRAL_BLOCKED', ['tenant_id' => $tenant->id, 'contact_id' => $invitedContact->id]);

            return false;
        }

        if (Referral::where('referred_contact_id', $invitedContact->id)->exists()) {
            Log::info('REFERRAL_ATTRIBUTION_ALREADY_ATTRIBUTED', ['tenant_id' => $tenant->id, 'contact_id' => $invitedContact->id]);

            return false;
        }

        if (Payment::where('contact_id', $invitedContact->id)->where('status', PaymentStatus::Confirmed)->exists()) {
            Log::info('REFERRAL_ATTRIBUTION_NOT_ELIGIBLE_PRIOR_PAYMENT', ['tenant_id' => $tenant->id, 'contact_id' => $invitedContact->id]);

            return false;
        }

        $this->createAttribution($invitedContact, $referrerContact, $code);

        return false;
    }

    private function createAttribution(Contact $invitedContact, Contact $referrerContact, string $code): void
    {
        try {
            DB::transaction(function () use ($invitedContact, $referrerContact, $code) {
                Referral::create([
                    'referred_contact_id' => $invitedContact->id,
                    'referrer_contact_id' => $referrerContact->id,
                    'code' => $code,
                ]);
            });

            Log::info('REFERRAL_ATTRIBUTION_CREATED', [
                'referred_contact_id' => $invitedContact->id,
                'referrer_contact_id' => $referrerContact->id,
                'code' => $code,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            // Carrera real (dos mensajes casi simultáneos del mismo
            // invitado) — el índice único en referred_contact_id ya
            // garantizó que solo una atribución exista; esta es la
            // perdedora, no-op silencioso.
            Log::info('REFERRAL_ATTRIBUTION_RACE_IDEMPOTENT_NOOP', ['contact_id' => $invitedContact->id]);
        }
    }
}
