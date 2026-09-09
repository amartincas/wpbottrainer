<?php

namespace App\Models;

use App\Training\Enums\TrainingAccessAuditAction;
use App\Training\Enums\TrainingAccessStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito 12 — registro append-only de una transición ADMINISTRATIVA de
 * `TrainingAccess` (nunca de una originada por un Payment confirmado — ver
 * migración). Nunca se edita ni se borra una fila ya creada. Único
 * escritor: `App\Training\Support\TrainingAccessAdministrationService`.
 *
 * NUNCA es la fuente de verdad del acceso actual — eso sigue siendo
 * exclusivamente `TrainingAccess.status`/`expires_at`. Esta tabla solo
 * responde "qué pasó, quién lo hizo, cuándo, por qué" — nunca "¿tiene
 * acceso ahora mismo?" (eso es `TrainingAccess::isCurrentlyValid()`).
 *
 * Hito 13 — `performed_by` es nullable exclusivamente cuando el origen es
 * una recompensa de Referidos (`referral_reward_id` no nulo) — nunca un
 * usuario "sistema" ficticio.
 *
 * Hito 15 — tercer origen posible: `auto_provisioned = true` identifica un
 * Trial concedido automáticamente por `App\Training\Support\
 * AutomaticTrialProvisioner`, sin actor humano ni recompensa de por medio.
 * `TrainingAccessAdministrationService::recordAudit()` impone en código, no
 * solo por convención, que exactamente UNO de los tres orígenes esté
 * presente (`performed_by` no nulo XOR `referral_reward_id` no nulo XOR
 * `auto_provisioned = true`) — nunca dos a la vez, nunca ninguno. Ver
 * docs/DECISIONS.md.
 */
#[Fillable([
    'contact_id',
    'training_access_id',
    'action',
    'performed_by',
    'referral_reward_id',
    'auto_provisioned',
    'previous_status',
    'new_status',
    'previous_expires_at',
    'new_expires_at',
    'reason',
])]
class TrainingAccessAudit extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'action' => TrainingAccessAuditAction::class,
            'previous_status' => TrainingAccessStatus::class,
            'new_status' => TrainingAccessStatus::class,
            'previous_expires_at' => 'datetime',
            'new_expires_at' => 'datetime',
            'created_at' => 'datetime',
            'auto_provisioned' => 'boolean',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function trainingAccess(): BelongsTo
    {
        return $this->belongsTo(TrainingAccess::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Hito 13 — presente si y solo si esta fila fue causada por una
     * recompensa de Referidos (nunca por un administrador). Ver
     * App\Referrals\Models\ReferralReward.
     */
    public function referralReward(): BelongsTo
    {
        return $this->belongsTo(\App\Referrals\Models\ReferralReward::class);
    }
}
