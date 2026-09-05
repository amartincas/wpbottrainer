<?php

namespace App\Models;

use App\Training\Enums\BodyRegion;
use App\Training\Enums\HealthConditionCategory;
use App\Training\Enums\HealthConditionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito de seguridad de restricciones (Bloque 2) — registro append-only de
 * lo que un usuario declaró sobre su salud/limitaciones. Nunca se
 * sobrescribe: una declaración nueva sobre el mismo tema crea una fila
 * nueva, nunca edita una existente (salvo los campos de revisión/estado,
 * que documentan qué pasó con ELLA, no reemplazan su contenido original).
 *
 * `original_text` es siempre el texto verbatim del usuario — nunca se
 * limpia, resume, ni reescribe. `suggested_body_region` es solo una
 * ETIQUETA determinista (ver BodyRegionCanonicalMapper), nunca una
 * restricción confirmada ni una interpretación de limitación funcional.
 *
 * NUNCA participa directamente en TrainingEngine — su único consumidor de
 * dominio es DeclaredHealthConditionRecorder, y su único efecto posible
 * sobre elegibilidad es, indirectamente, a través de una
 * TrainingRestriction creada por revisión humana explícita
 * (ver DeclaredHealthConditionRecorder::resolveWithRestriction()).
 */
#[Fillable([
    'contact_id',
    'original_text',
    'source_message_id',
    'category',
    'suggested_body_region',
    'status',
    'related_restriction_id',
    'reviewed_by',
    'reviewed_at',
    'review_note',
    'declared_at',
])]
class DeclaredHealthCondition extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => HealthConditionCategory::class,
            'suggested_body_region' => BodyRegion::class,
            'status' => HealthConditionStatus::class,
            'reviewed_at' => 'datetime',
            'declared_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Trazabilidad hacia el mensaje de WhatsApp original — nullable porque
     * no toda declaración tiene un mensaje puntual identificable, pero se
     * puebla siempre que exista (Regla 10 del Bloque 2).
     */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class, 'source_message_id');
    }

    /**
     * Solo se puebla cuando `status=resolved_restriction_created` — ver
     * DeclaredHealthConditionRecorder::resolveWithRestriction(), el único
     * camino de creación de TrainingRestriction en este bloque.
     */
    public function relatedRestriction(): BelongsTo
    {
        return $this->belongsTo(TrainingRestriction::class, 'related_restriction_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
