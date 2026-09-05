<?php

namespace App\Models;

use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\SafetyStatus;
use App\Training\Enums\Sex;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'contact_id',
    'goal',
    'experience_level',
    'primary_focus',
    'secondary_focus',
    'available_equipment',
    'equipment_fully_equipped',
    'restrictions',
    'sessions_per_week',
    'age',
    'sex',
    'weight_kg',
    'height_cm',
    'physical_stats_asked',
    'training_location',
    'split_type',
    'next_focus',
    'safety_status',
    'safety_flag_reason',
    'safety_flagged_at',
    'safety_reviewed_by',
    'safety_reviewed_at',
    'safety_review_note',
])]
class TrainingProfile extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'goal' => TrainingGoal::class,
            'experience_level' => ExperienceLevel::class,
            'primary_focus' => 'array',
            'secondary_focus' => 'array',
            'available_equipment' => 'array',
            'equipment_fully_equipped' => 'boolean',
            'restrictions' => 'array',
            'sessions_per_week' => 'integer',
            'age' => 'integer',
            'sex' => Sex::class,
            'weight_kg' => 'decimal:2',
            'height_cm' => 'integer',
            'physical_stats_asked' => 'boolean',
            'training_location' => TrainingLocation::class,
            'split_type' => SplitType::class,
            'safety_status' => SafetyStatus::class,
            'safety_flagged_at' => 'datetime',
            'safety_reviewed_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Hito de seguridad de restricciones — fuente NUEVA y estructurada de
     * restricciones (contrato canónico), vía `contact_id`. Convive con
     * `restrictions` (legacy, texto libre) — SOLO `SafetyRestrictionResolver`
     * conoce ambas a la vez; este modelo no las mezcla ni decide nada.
     */
    public function trainingRestrictions(): HasMany
    {
        return $this->hasMany(TrainingRestriction::class, 'contact_id', 'contact_id');
    }

    /**
     * Hito 8: quién levantó el flag de seguridad — siempre un humano
     * autorizado, nunca el LLM. Nullable: un perfil actualmente flagged
     * (o que nunca fue revisado) no tiene uno.
     */
    public function safetyReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'safety_reviewed_by');
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
            // Hito 8: cualquier revisión previa queda invalidada por una
            // señal nueva — un humano ya había revisado y desbloqueado un
            // incidente anterior, no este. Sin esto, el panel mostraría
            // datos de una revisión que ya no aplica.
            'safety_reviewed_by' => null,
            'safety_reviewed_at' => null,
            'safety_review_note' => null,
        ]);
    }

    /**
     * Hito 7.1 → implementado en Hito 8: única forma de desbloquear un
     * perfil marcado — siempre una acción humana explícita y autorizada
     * (is_super_admin, ver App\Filament\Resources\Contacts), nunca
     * automática ni por el LLM. $note es obligatoria (App\Filament valida
     * esto en la UI; el modelo no impone longitud mínima).
     */
    public function clearSafetyFlag(User $reviewer, string $note): void
    {
        $this->update([
            'safety_status' => SafetyStatus::Normal,
            'safety_flag_reason' => null,
            'safety_flagged_at' => null,
            'safety_reviewed_by' => $reviewer->id,
            'safety_reviewed_at' => now(),
            'safety_review_note' => $note,
        ]);
    }

    public function isFlaggedForSafetyReview(): bool
    {
        return $this->safety_status === SafetyStatus::FlaggedForReview;
    }

    /**
     * Bloque 3 — congela ÚNICAMENTE los campos de este perfil que realmente
     * participan en la decisión de `TrainingEngine` (verificado leyendo su
     * código, no supuesto): `age`/`sex`/`weight_kg`/`height_cm`/
     * `physical_stats_asked`/`sessions_per_week`/`restrictions` crudo/todo
     * el bloque `safety_*` quedan deliberadamente FUERA — sin consumidor
     * real en la prescripción (o, en el caso de `restrictions`, ya nivelado
     * en `$activeSafetyTags`).
     *
     * Este modelo NO conoce `SafetyRestrictionResolver` ni `TrainingEngine`
     * — por eso el foco decidido y los tags de seguridad ya nivelados se
     * reciben como parámetros, calculados por quien sí los conoce
     * (`TrainingEngine`), exactamente igual que `Exercise::toSnapshot()` no
     * depende de nada externo a `Exercise` mismo.
     *
     * @param  array<int, string>  $activeSafetyTags  Resultado de
     *         SafetyRestrictionResolver::activeSafetyBodyRegions($this) en
     *         el mismo instante — nunca recalculado aquí, para no duplicar
     *         lógica de seguridad.
     * @param  \DateTimeInterface  $generatedAt  Fundación Temporal (Bloque 3,
     *         ver docs/DECISIONS.md D046): representa `prescribed_at` —el
     *         instante exacto en que `TrainingEngine` tomó ESTA decisión de
     *         prescripción—, NUNCA el momento en que el mensaje se entregó
     *         por WhatsApp (ese timestamp no existe todavía como campo) ni
     *         un compromiso de programación futura. Es un concepto distinto
     *         de `WorkoutSession.scheduled_at` (el instante para el que la
     *         sesión está prevista), aunque hoy ambos coincidan porque
     *         `TrainingEngine` no soporta programación anticipada.
     */
    public function toPrescriptionContextSnapshot(string $decidedFocus, array $activeSafetyTags, \DateTimeInterface $generatedAt): array
    {
        return [
            'schema_version' => 1,
            'goal' => $this->goal?->value,
            'experience_level' => $this->experience_level?->value,
            'primary_focus' => $this->primary_focus ?? [],
            'secondary_focus' => $this->secondary_focus ?? [],
            'decided_focus' => $decidedFocus,
            'split_type' => $this->split_type->value,
            'training_location' => $this->training_location?->value,
            'available_equipment' => $this->available_equipment ?? [],
            'equipment_fully_equipped' => $this->equipment_fully_equipped,
            'active_safety_tags' => array_values($activeSafetyTags),
            // prescribed_at conceptual — ver docblock del parámetro $generatedAt.
            'generated_at' => $generatedAt->toISOString(),
        ];
    }

    /**
     * Hito 9.0: `sessions_per_week` no tenía ningún consumidor real en
     * `TrainingEngine` — se capturaba y persistía, pero no cambiaba nada
     * (hallazgo explícito, ver docs/DECISIONS.md). Esta es su única
     * consecuencia real: deriva el `split_type` determinísticamente, sin
     * IA, cuando el onboarding captura/actualiza la frecuencia semanal.
     *
     * HEURÍSTICA DE PRODUCTO documentada como tal (mismo criterio que
     * GOAL_DEFAULTS de TrainingEngine) — no una prescripción científica
     * universal, revisable en cualquier momento sin migración: pocos días
     * favorecen entrenar todo el cuerpo cada vez (full_body); más días
     * permiten separar grupos musculares para dar más recuperación entre
     * sesiones del mismo grupo (upper_lower / push_pull_legs).
     */
    public static function deriveSplitTypeFromSessionsPerWeek(int $sessionsPerWeek): SplitType
    {
        return match (true) {
            $sessionsPerWeek <= 3 => SplitType::FullBody,
            $sessionsPerWeek === 4 => SplitType::UpperLower,
            default => SplitType::PushPullLegs, // 5+
        };
    }

    /**
     * Onboarding mínimo completo (Hito 5, ampliado en Hito 8.3 y 8.4):
     * nombre (`Contact.customer_name` — identidad, no vive en este modelo),
     * objetivo, nivel, zona a priorizar (`primary_focus`), lugar de
     * entrenamiento, equipamiento, restricciones, sesiones/semana, y haber
     * preguntado los datos físicos una vez (`physical_stats_asked`).
     * `restrictions`/`available_equipment`/`primary_focus` distinguen
     * "todavía no preguntado" (null) de "preguntado, sin ninguno" (`[]`) —
     * solo lo primero cuenta como incompleto.
     *
     * Deliberadamente NO exige `age`/`sex`/`weight_kg`/`height_cm` — se
     * capturan desde MVP (docs/DECISIONS.md) pero nunca bloquean el
     * onboarding; se preguntan una sola vez (`physical_stats_asked`) y se
     * acepta cualquier respuesta, incluida ninguna.
     */
    public function isOnboardingComplete(Contact $contact): bool
    {
        return $this->firstMissingOnboardingField($contact) === null;
    }

    /**
     * El primer campo obligatorio todavía sin responder, en el orden en que
     * se pregunta — o null si el onboarding ya está completo. El código
     * decide cuál falta (Decide); el LLM solo redacta la pregunta (Narrate).
     *
     * Orden (Hito 8.4): nombre → objetivo → nivel → zona a priorizar →
     * lugar → equipamiento → restricciones → frecuencia → datos físicos.
     * `primary_focus` tiene pregunta dedicada obligatoria (no es puramente
     * oportunista) — ver docs/DECISIONS.md D034. `secondary_focus` nunca
     * bloquea el onboarding: es una representación interna derivada, el
     * usuario nunca la ve ni la responde directamente.
     */
    public function firstMissingOnboardingField(Contact $contact): ?string
    {
        return match (true) {
            $contact->customer_name === null => 'name',
            $this->goal === null => 'goal',
            $this->experience_level === null => 'experience_level',
            $this->primary_focus === null => 'primary_focus',
            $this->training_location === null => 'training_location',
            $this->available_equipment === null => 'available_equipment',
            $this->restrictions === null => 'restrictions',
            $this->sessions_per_week === null => 'sessions_per_week',
            ! $this->physical_stats_asked => 'physical_stats',
            default => null,
        };
    }
}
