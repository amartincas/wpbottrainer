<?php

namespace App\Models;

use App\Training\Enums\BodyRegion;
use App\Training\Enums\RestrictionSource;
use App\Training\Enums\RestrictionStatus;
use App\Training\Enums\RestrictionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hito de seguridad de restricciones — fuente NUEVA y estructurada de
 * restricciones de usuario, contrato canónico (body_region/
 * restriction_type/source/status). Solo `status=Confirmed` participa en
 * TrainingEngine::isEligible() (vía SafetyRestrictionResolver, nunca
 * directamente). `source` es inmutable una vez creado el registro — nunca
 * se reescribe con "quién lo confirmó"; eso vive en reviewed_by/reviewed_at.
 */
#[Fillable([
    'contact_id',
    'body_region',
    'restriction_type',
    'source',
    'status',
    'original_text',
    'declared_health_condition_id',
    'reviewed_by',
    'reviewed_at',
])]
class TrainingRestriction extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'body_region' => BodyRegion::class,
            'restriction_type' => RestrictionType::class,
            'source' => RestrictionSource::class,
            'status' => RestrictionStatus::class,
            'reviewed_at' => 'datetime',
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
}
