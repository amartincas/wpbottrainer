<?php

namespace App\Referrals\Models;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito 13 — identidad pública de un Contact como referente. Un código por
 * Contact (generado perezosamente la primera vez que lo pide), estable —
 * no regenerable en este hito. Vive en `App\Referrals\Models` (no
 * `App\Models`), mismo criterio que `App\Payments\Models\MembershipPlan`:
 * un dominio nuevo, autocontenido, en principio removible sin tocar nada
 * más. Ver docs/DECISIONS.md.
 */
#[Fillable(['contact_id', 'code'])]
class ReferralCode extends Model
{
    use HasFactory;

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
