<?php

namespace App\CustomerCare\Models;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito 14 — registro append-only de una solicitud de atención humana.
 * `$timestamps = false`, solo `created_at` con `useCurrent()` — mismo
 * patrón que `TrainingAccessAudit`/`ReferralReward`. Es la ÚNICA fuente de
 * verdad de la solicitud — `AlertLog` sigue siendo exclusivamente
 * infraestructura de notificación (ver
 * App\CustomerCare\Support\CustomerServiceRequestRecorder y
 * docs/DECISIONS.md).
 */
#[Fillable(['contact_id', 'message'])]
class CustomerServiceRequest extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
