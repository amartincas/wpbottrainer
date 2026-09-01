<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de comprobante enviado para un Payment — separado de Payment
 * a propósito, para conservar cada intento (una foto borrosa, luego una más
 * clara) sin perder historial. Ver docs/DECISIONS.md.
 */
#[Fillable(['payment_id', 'file_path', 'mime_type', 'file_hash', 'source_type', 'extracted_data'])]
class PaymentReceipt extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'extracted_data' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
