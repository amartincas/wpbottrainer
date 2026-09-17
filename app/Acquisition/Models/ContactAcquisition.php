<?php

namespace App\Acquisition\Models;

use App\Acquisition\Enums\AcquisitionSource;
use App\Models\Contact;
use App\Referrals\Models\Referral;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-B — atribución de adquisición inicial de un Contact. Vive en
 * `App\Acquisition\Models` (no `App\Models`), mismo criterio que
 * `App\Referrals\Models\Referral`/`App\Payments\Models\MembershipPlan`: un
 * dominio nuevo, autocontenido, en principio removible sin tocar nada más.
 *
 * `contact_id` es UNIQUE a nivel de BD (ver migración) — esa es la garantía
 * real de first-touch, no una convención de código: como máximo una fila
 * por Contact, en toda su vida, nunca se reemplaza. Este modelo nunca debe
 * usarse con `updateOrCreate()`/`upsert()` para modificar una fila
 * existente — únicamente `firstOrCreate()`/`create()` protegido por el
 * índice único (ver App\Acquisition\Support\AcquisitionSourcePreRoutingScreen).
 */
#[Fillable([
    'contact_id',
    'source',
    'meta_ad_id',
    'meta_ctwa_clid',
    'meta_source_type',
    'meta_headline',
    'meta_body',
    'meta_media_url',
    'raw_referral_payload',
    'referral_id',
])]
class ContactAcquisition extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'source' => AcquisitionSource::class,
            'raw_referral_payload' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Solo tiene valor real cuando `source === AcquisitionSource::Referral`
     * — nunca se escribe ni se lee para meta_ads/organic.
     */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }
}
