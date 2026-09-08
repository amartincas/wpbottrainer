<?php

namespace App\Referrals\Models;

use App\Models\Payment;
use App\Referrals\Enums\ReferralRewardApplicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito 13 — historial append-only de recompensas efectivamente generadas
 * (`$timestamps = false`, solo `created_at` con `useCurrent()` en la
 * migración — mismo patrón que `App\Models\TrainingAccessAudit`). Nunca se
 * edita una fila existente.
 *
 * `referral_id`/`payment_id` UNIQUE a nivel de BD — dos ejes ortogonales
 * de idempotencia: como máximo una recompensa por Referral en toda su
 * vida, y como máximo una recompensa por Payment (protege contra evento
 * duplicado/listener reintentado). Ver
 * App\Referrals\Listeners\ApplyReferralRewardOnPaymentConfirmed y
 * docs/DECISIONS.md.
 *
 * `reward_days` es un SNAPSHOT de `Tenant.referral_reward_days` en el
 * momento exacto de la recompensa — nunca se relee después.
 *
 * `application_status`: ver App\Referrals\Enums\ReferralRewardApplicationStatus.
 * `Pending` es la representación estructural de una recompensa que existe
 * pero no pudo aplicarse — consultable directamente, nunca depende de
 * AlertLog para saber que existe.
 */
#[Fillable(['referral_id', 'payment_id', 'reward_days', 'application_status'])]
class ReferralReward extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'reward_days' => 'integer',
            'application_status' => ReferralRewardApplicationStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
