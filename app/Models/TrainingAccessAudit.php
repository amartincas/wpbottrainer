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
 */
#[Fillable([
    'contact_id',
    'training_access_id',
    'action',
    'performed_by',
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
}
