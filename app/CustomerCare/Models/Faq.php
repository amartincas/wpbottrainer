<?php

namespace App\CustomerCare\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito 14 — FAQ configurable por Tenant desde Filament. La `answer` es el
 * conocimiento autorizado — la fuente de verdad — pero no necesariamente el
 * texto literal enviado al usuario: la redacción final la hace la IA,
 * grounded exclusivamente en este campo (ver
 * App\CustomerCare\Support\FaqMatcher y docs/DECISIONS.md).
 *
 * Vive en `App\CustomerCare\Models` (no `App\Models`), mismo criterio que
 * `App\Payments\Models\MembershipPlan`: dominio nuevo, autocontenido.
 */
#[Fillable(['tenant_id', 'question', 'answer', 'is_active', 'sort_order'])]
class Faq extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
