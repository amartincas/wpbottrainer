<?php

namespace App\Referrals\Models;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Hito 13 — atribución: qué Contact fue referido por cuál otro, y con qué
 * código. `referred_contact_id` es UNIQUE a nivel de BD (ver migración) —
 * una atribución efectiva por referido, en toda su vida, nunca se
 * reemplaza (ejemplo Juan/Pedro/Laura del diseño: la primera gana).
 *
 * Deliberadamente SIN columna `status` — "atribuido" vs "recompensado" es
 * 100% derivable de si existe un `ReferralReward` asociado
 * (`isRewarded()`), nunca un valor guardado que pudiera desincronizarse.
 */
#[Fillable(['referred_contact_id', 'referrer_contact_id', 'code'])]
class Referral extends Model
{
    use HasFactory;

    public function referredContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'referred_contact_id');
    }

    public function referrerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'referrer_contact_id');
    }

    public function reward(): HasOne
    {
        return $this->hasOne(ReferralReward::class);
    }

    public function isRewarded(): bool
    {
        return $this->reward()->exists();
    }
}
