<?php

namespace App\Payments\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito 11 — catálogo de opciones de compra única (duración + precio) por
 * Tenant. Deliberadamente en `App\Payments\Models`, no en `App\Models` —
 * es una entidad propia del dominio Payments, no una identidad compartida
 * transversal como `Contact`/`Tenant`. NO es `Subscription`: sin
 * facturación recurrente, sin renovación automática, sin invoices, sin
 * prorrateo.
 *
 * NUNCA es la fuente de verdad para un `Payment` ya creado — ver
 * `App\Models\Payment::membership_plan_id`/`membership_months`. Cambiar el
 * `price`/`duration_months` de una fila existente, o desactivarla, no
 * altera ningún `Payment` histórico que ya la haya referenciado.
 */
#[Fillable(['tenant_id', 'label', 'duration_months', 'price', 'currency', 'is_active', 'sort_order'])]
class MembershipPlan extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
