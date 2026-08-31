<?php

namespace App\Models;

use App\Training\Enums\TrainingAccessStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entitlement/acceso vigente al servicio de Training — deliberadamente
 * separado de TrainingProfile. NO es Subscription/Payment/Invoice/
 * Enrollment. Único consumidor real: App\Training\Support\TrainingAccessGate.
 * Ver docs/DECISIONS.md.
 */
#[Fillable([
    'contact_id',
    'status',
    'granted_at',
    'expires_at',
    'granted_by',
    'notes',
])]
class TrainingAccess extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TrainingAccessStatus::class,
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isCurrentlyValid(): bool
    {
        if (! in_array($this->status, [TrainingAccessStatus::Trial, TrainingAccessStatus::Active], true)) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
