<?php

namespace App\Models;

use App\Payments\Enums\PaymentMethodType;
use App\Payments\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Evidencia de una transacción puntual (Hito 8) — NO Subscription, NO
 * TrainingAccess. Sin `tenant_id` propio (se alcanza vía `Contact`, mismo
 * precedente que TrainingProfile/WorkoutSession/TrainingAccess del Hito 4).
 * Ver docs/DECISIONS.md.
 *
 * Nunca se modifica `TrainingAccess` directamente desde aquí ni desde
 * PaymentHandler — el único camino es App\Payments\Support\
 * PaymentConfirmationService, invocado tras una decisión humana (o, en el
 * futuro, una pasarela).
 */
#[Fillable([
    'contact_id',
    'amount',
    'currency',
    'method',
    'method_label',
    'reference',
    'status',
    'extracted_data',
    'validation_flags',
    'receipt_submitted_at',
    'reviewed_by',
    'reviewed_at',
    'review_note',
    'expires_at',
])]
class Payment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethodType::class,
            'status' => PaymentStatus::class,
            'extracted_data' => 'array',
            'validation_flags' => 'array',
            'receipt_submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    public function isAwaitingReceipt(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [PaymentStatus::Pending, PaymentStatus::UnderReview], true);
    }
}
