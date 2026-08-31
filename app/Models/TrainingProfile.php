<?php

namespace App\Models;

use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\SafetyStatus;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'contact_id',
    'goal',
    'experience_level',
    'available_equipment',
    'restrictions',
    'sessions_per_week',
    'split_type',
    'next_focus',
    'safety_status',
    'safety_flag_reason',
    'safety_flagged_at',
])]
class TrainingProfile extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'goal' => TrainingGoal::class,
            'experience_level' => ExperienceLevel::class,
            'available_equipment' => 'array',
            'restrictions' => 'array',
            'sessions_per_week' => 'integer',
            'split_type' => SplitType::class,
            'safety_status' => SafetyStatus::class,
            'safety_flagged_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Marca el perfil como bloqueado por una señal de seguridad. Solo debe
     * ser invocado por una regla determinista (nunca por el LLM decidiendo
     * por sí solo) — ver App\Training\Support\SafetySignalDetector y
     * docs/DECISIONS.md.
     */
    public function flagForSafetyReview(string $reason): void
    {
        $this->update([
            'safety_status' => SafetyStatus::FlaggedForReview,
            'safety_flag_reason' => $reason,
            'safety_flagged_at' => now(),
        ]);
    }

    /**
     * Solo debe invocarse desde una acción humana explícita (superadmin/
     * profesional del tenant) — nunca automáticamente ni por el LLM.
     */
    public function clearSafetyFlag(): void
    {
        $this->update([
            'safety_status' => SafetyStatus::Normal,
            'safety_flag_reason' => null,
            'safety_flagged_at' => null,
        ]);
    }

    public function isFlaggedForSafetyReview(): bool
    {
        return $this->safety_status === SafetyStatus::FlaggedForReview;
    }

    /**
     * Onboarding mínimo completo (Hito 5): objetivo, nivel, restricciones,
     * equipamiento y sesiones/semana. `restrictions`/`available_equipment`
     * distinguen "todavía no preguntado" (null) de "preguntado, sin ninguno"
     * (`[]`) — solo lo primero cuenta como incompleto.
     */
    public function isOnboardingComplete(): bool
    {
        return $this->goal !== null
            && $this->experience_level !== null
            && $this->sessions_per_week !== null
            && $this->restrictions !== null
            && $this->available_equipment !== null;
    }

    /**
     * El primer campo obligatorio todavía sin responder, en el orden en que
     * se pregunta — o null si el onboarding ya está completo. El código
     * decide cuál falta (Decide); el LLM solo redacta la pregunta (Narrate).
     */
    public function firstMissingOnboardingField(): ?string
    {
        return match (true) {
            $this->goal === null => 'goal',
            $this->experience_level === null => 'experience_level',
            $this->restrictions === null => 'restrictions',
            $this->available_equipment === null => 'available_equipment',
            $this->sessions_per_week === null => 'sessions_per_week',
            default => null,
        };
    }
}
