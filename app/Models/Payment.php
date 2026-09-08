<?php

namespace App\Models;

use App\Payments\Enums\PaymentMethodType;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\MembershipPlan;
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
 *
 * Hito 11 — snapshot histórico de la membresía comprada: `membership_plan_id`
 * es SOLO procedencia (nunca se relee para decidir comportamiento — si el
 * `MembershipPlan` cambia de precio/duración, se desactiva, o se borra,
 * este `Payment` sigue representando la compra original exactamente como
 * ocurrió). `membership_months` es el snapshot real que gobierna
 * `PaymentConfirmationService::grantAccess()`; `amount`/`currency`
 * (ya existentes) cumplen el mismo rol de snapshot para el precio — se
 * pueblan desde el `MembershipPlan` elegido al crear el Payment, nunca se
 * recalculan después. `membership_months` es nullable a propósito: todo
 * `Payment` creado antes de este hito no tiene valor aquí y debe seguir
 * concediendo 1 mes vía el fallback `LEGACY_DEFAULT_MONTHS` en
 * `PaymentConfirmationService` — nunca se hace backfill de datos
 * históricos para rellenar esta columna.
 */
#[Fillable([
    'contact_id',
    'membership_plan_id',
    'membership_months',
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
            'membership_months' => 'integer',
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

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
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

    /**
     * Hito 11 — un Payment recién creado (tras elegir método) que todavía
     * no tiene una membresía seleccionada. `PaymentHandler` debe pedir el
     * plan antes de enviar instrucciones de pago o aceptar un comprobante
     * — nunca antes de esto.
     */
    public function needsPlanSelection(): bool
    {
        return $this->isAwaitingReceipt() && $this->membership_plan_id === null;
    }
}
