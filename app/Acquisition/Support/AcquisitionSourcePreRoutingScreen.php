<?php

namespace App\Acquisition\Support;

use App\Acquisition\Enums\AcquisitionSource;
use App\Acquisition\Models\ContactAcquisition;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\PreRoutingScreenInterface;
use App\Core\Support\DetectsUniqueConstraintViolation;
use App\Models\Contact;
use App\Referrals\Models\Referral;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * P1-B — captura de atribución de adquisición: corre para TODO mensaje,
 * ANTES de cualquier clasificación de Intent, DESPUÉS de
 * ReferralAttributionPreRoutingScreen (mismo mecanismo que
 * SafetySignalPreRoutingScreen/ReferralAttributionPreRoutingScreen, Hito 7/13)
 * — necesita correr después de Referrals para poder ver, en la MISMA
 * pasada, si ese screen (sin modificar) acaba de crear un `Referral` para
 * este Contact.
 *
 * Igual que ReferralAttributionPreRoutingScreen, `screen()` NUNCA reclama
 * el pipeline — siempre retorna `false`. Es puramente un efecto secundario
 * (crear la atribución si corresponde) que nunca desvía al usuario del
 * flujo normal.
 *
 * Regla de first-touch (P1-B): SI YA EXISTE una `ContactAcquisition` para
 * este Contact, este screen es un no-op total — nunca la modifica, nunca
 * la reinterpreta, nunca la sobrescribe, sin importar qué traiga el
 * mensaje actual. La garantía real es el índice único en
 * `contact_acquisitions.contact_id` (ver migración) — el chequeo de
 * `exists()` de abajo es solo la vía optimista; ante una carrera genuina
 * (dos mensajes casi simultáneos del mismo Contact nuevo), la base de
 * datos decide y la perdedora se trata como no-op silencioso (mismo
 * patrón exacto que ReferralAttributionPreRoutingScreen::createAttribution()).
 *
 * Prioridad de clasificación (una vez confirmado que NO existe atribución
 * previa):
 *   1. `referral` de Meta presente en ESTE mensaje -> meta_ads (no exige
 *      ningún campo específico dentro de `referral` — la sola presencia de
 *      un objeto `referral` no nulo ya es la señal).
 *   2. si no, un `Referral` (App\Referrals) ya existe para este Contact
 *      -> referral (nunca escribe ni condiciona App\Referrals, solo LEE su
 *      resultado — ese sistema permanece intacto).
 *   3. si ninguna de las dos -> organic (fila explícita, nunca la ausencia
 *      de fila — ver auditoría P1-B).
 *
 * Caso límite documentado, deliberadamente NO resuelto aquí: si
 * SafetySignalPreRoutingScreen reclama el pipeline en el primer mensaje de
 * un Contact nuevo (screen() retorna true), este screen nunca llega a
 * ejecutarse para ese mensaje y la atribución de ese mensaje se pierde. No
 * se reordena Safety para evitar esto — ver informe de P1-B.
 */
class AcquisitionSourcePreRoutingScreen implements PreRoutingScreenInterface
{
    use DetectsUniqueConstraintViolation;

    public function screen(ExecutionContext $context): bool
    {
        $tenant = $context->tenant;

        $contact = Contact::firstOrCreate(
            ['tenant_id' => $tenant->id, 'customer_phone' => $context->message->from],
            ['summary' => 'Registro de Acquisition (atribución)', 'bot_active' => true],
        );

        if (ContactAcquisition::where('contact_id', $contact->id)->exists()) {
            // First-touch ya ganado — nunca se modifica, nunca se
            // reinterpreta, sin importar qué traiga este mensaje.
            return false;
        }

        $referral = $context->message->referral;

        if ($referral !== null) {
            $this->createAcquisition($contact, $this->buildMetaAdsAttributes($referral));

            return false;
        }

        $existingReferral = Referral::where('referred_contact_id', $contact->id)->first();

        if ($existingReferral !== null) {
            $this->createAcquisition($contact, [
                'source' => AcquisitionSource::Referral,
                'referral_id' => $existingReferral->id,
            ]);

            return false;
        }

        $this->createAcquisition($contact, ['source' => AcquisitionSource::Organic]);

        return false;
    }

    /**
     * Mapea el `referral` crudo de Meta (metadata de transporte, nunca
     * interpretada antes de este punto — ver App\Core\Messaging\IngestedMessage)
     * a los campos de snapshot. No exige ningún campo particular: la sola
     * presencia de `$referral` (no nulo) ya clasifica como meta_ads;
     * cualquier campo ausente en el array queda NULL. `meta_media_url` se
     * resuelve por el primer campo de media realmente presente
     * (image_url -> video_url -> thumbnail_url), sin inventar ningún valor.
     *
     * @param  array<string, mixed>  $referral
     * @return array<string, mixed>
     */
    private function buildMetaAdsAttributes(array $referral): array
    {
        return [
            'source' => AcquisitionSource::MetaAds,
            'meta_ad_id' => $referral['source_id'] ?? null,
            'meta_ctwa_clid' => $referral['ctwa_clid'] ?? null,
            'meta_source_type' => $referral['source_type'] ?? null,
            'meta_headline' => $referral['headline'] ?? null,
            'meta_body' => $referral['body'] ?? null,
            'meta_media_url' => $referral['image_url'] ?? $referral['video_url'] ?? $referral['thumbnail_url'] ?? null,
            'raw_referral_payload' => $referral,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAcquisition(Contact $contact, array $attributes): void
    {
        try {
            ContactAcquisition::create(['contact_id' => $contact->id, ...$attributes]);

            Log::info('ACQUISITION_SOURCE_CREATED', [
                'contact_id' => $contact->id,
                'source' => $attributes['source']->value,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            // Carrera real (dos mensajes casi simultáneos del mismo Contact
            // nuevo) — el índice único en contact_id ya garantizó que solo
            // una atribución exista; esta es la perdedora, no-op silencioso.
            Log::info('ACQUISITION_SOURCE_RACE_IDEMPOTENT_NOOP', ['contact_id' => $contact->id]);
        }
    }
}
