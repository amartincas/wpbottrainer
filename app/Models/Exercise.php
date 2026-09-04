<?php

namespace App\Models;

use App\Training\Enums\MovementPattern;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\TrackingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'name_es',
    'slug',
    'description',
    'instructions',
    'instructions_es',
    'important_points',
    'important_points_es',
    'common_mistakes',
    'breathing_cue',
    'video_url',
    'muscle_group',
    'primary_muscle',
    'secondary_muscles',
    'movement_pattern',
    'equipment_needed',
    'difficulty_level',
    'contraindications',
    'tracking_type',
    'is_active',
    'provider',
    'provider_exercise_id',
    'provider_metadata',
    'provider_has_video',
    'exercise_type',
    'video_duration_seconds',
    'synced_at',
    'content_translated_at',
    'contraindications_reviewed_at',
    'contraindications_reviewed_by',
])]
class Exercise extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'instructions' => 'array',
            'instructions_es' => 'array',
            'important_points' => 'array',
            'important_points_es' => 'array',
            'common_mistakes' => 'array',
            'primary_muscle' => MuscleFocus::class,
            'secondary_muscles' => 'array',
            'movement_pattern' => MovementPattern::class,
            'equipment_needed' => 'array',
            'contraindications' => 'array',
            'tracking_type' => TrackingType::class,
            'is_active' => 'boolean',
            'provider_metadata' => 'array',
            'provider_has_video' => 'boolean',
            'exercise_type' => 'array',
            'video_duration_seconds' => 'integer',
            'synced_at' => 'datetime',
            'content_translated_at' => 'datetime',
            'contraindications_reviewed_at' => 'datetime',
        ];
    }

    /**
     * Hito 9.1 — invariante estructural, no solo documentada: un Exercise
     * de proveedor (`provider` no nulo) NUNCA puede tener `video_url`
     * directo. La URL siempre se resuelve fresca en el momento del envío
     * (ver App\ExerciseCatalog\MediaResolver) — persistirla, aunque sea de
     * paso, es exactamente lo que Hito 9 prohíbe explícitamente. Se aplica
     * a nivel de modelo, no solo por convención del Importer, para que
     * ninguna otra vía de escritura pueda violarla accidentalmente.
     */
    protected static function booted(): void
    {
        static::saving(function (Exercise $exercise) {
            if ($exercise->provider !== null && $exercise->video_url !== null) {
                throw new \DomainException(
                    "Exercise con provider='{$exercise->provider}' no puede tener video_url directo — usar MediaResolver."
                );
            }
        });
    }

    public function workoutExercises(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class);
    }

    /**
     * Hito 9.3 (post-deploy) — historial append-only de resoluciones de
     * video EXITOSAS (ver App\ExerciseCatalog\MediaResolver y la migración
     * de esta tabla). Nunca confundir con `provider_has_video` (señal
     * cruda del proveedor, nunca confirmada) — ver videoValidated().
     */
    public function videoAccesses(): HasMany
    {
        return $this->hasMany(ExerciseVideoAccess::class);
    }

    /**
     * Hito 9.3 (post-deploy) — "video validado" según NUESTRO propio
     * registro (ExerciseVideoAccess), nunca según lo que el proveedor
     * *dice* tener (`provider_has_video`). Es posible que
     * `provider_has_video=true` y `videoValidated()=false` a la vez —
     * significa que el proveedor lo ofrece pero todavía nadie lo probó
     * con éxito desde este sistema. Único lugar que calcula esto — mismo
     * criterio que reviewStatus().
     */
    public function videoValidated(): bool
    {
        return $this->relationLoaded('videoAccesses')
            ? $this->videoAccesses->isNotEmpty()
            : $this->videoAccesses()->exists();
    }

    /**
     * Filtro reutilizable para Filament (ExercisesTable) — nunca duplica
     * la regla de reviewStatus(), la traduce a una consulta SQL
     * equivalente. Único lugar que lo hace.
     */
    public function scopeWithReviewStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'active' => $query->where('is_active', true),
            'pending_review' => $query->where('is_active', false)->whereNull('contraindications'),
            'inactive' => $query->where('is_active', false)->whereNotNull('contraindications'),
            default => $query,
        };
    }

    /**
     * Hito 9.1: quién revisó (y confirmó, aunque sea vacía) la lista de
     * contraindicaciones de este ejercicio — mismo patrón que
     * TrainingProfile.safetyReviewedBy(). Nullable: un ejercicio recién
     * importado de un proveedor (o nunca revisado) no tiene uno.
     */
    public function contraindicationsReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contraindications_reviewed_by');
    }

    /**
     * Hito 9.3 (ExerciseResource) — estado de revisión DERIVADO de
     * `is_active`+`contraindications`, deliberadamente sin columna nueva:
     * - `pending_review`: nunca revisado (`contraindications=null`).
     * - `active`: aprobado y en uso.
     * - `inactive`: fue revisado alguna vez (`contraindications!==null`)
     *   pero ya no está activo — ej. el proveedor dejó de devolverlo.
     *
     * Único lugar que calcula esto — Filament (y cualquier otro
     * consumidor futuro) lo lee de aquí, nunca reimplementa la regla.
     */
    public function reviewStatus(): string
    {
        return match (true) {
            $this->is_active => 'active',
            $this->contraindications === null => 'pending_review',
            default => 'inactive',
        };
    }

    /**
     * Hito 9.1/9.2 — única vía para activar un Exercise de proveedor.
     * Rechaza explícitamente activar cualquier ejercicio cuyas
     * `contraindications` sigan en `null` ("todavía no revisado" — mismo
     * convenio null/[] que `restrictions`/`available_equipment`/
     * `primary_focus`). `[]` SÍ es válido: significa que un humano ya
     * revisó y confirmó que no hay ninguna contraindicación conocida —
     * nunca se asume solo porque el proveedor no la informó.
     *
     * `instructions` vacío (`null` o `[]`) TAMBIÉN bloquea — a diferencia
     * de `contraindications`, aquí no existe un "revisado, confirmado sin
     * nada" válido: sin pasos no hay "cómo ejecutar" que mostrarle al
     * usuario, el ejercicio simplemente no está listo.
     *
     * `important_points`/`common_mistakes`/`breathing_cue` en `null`
     * NUNCA bloquean — asimetría deliberada (ver docs/DECISIONS.md): son
     * contenido opcional, editable por un administrador cuando exista
     * información confiable, nunca inventado solo para poder activar.
     */
    public function activate(User $reviewer): void
    {
        if ($this->contraindications === null) {
            throw new \DomainException(
                "Exercise {$this->id} no puede activarse sin contraindications revisadas — ver docs/DECISIONS.md."
            );
        }

        if (empty($this->instructions)) {
            throw new \DomainException(
                "Exercise {$this->id} no puede activarse sin instructions — ver docs/DECISIONS.md."
            );
        }

        $this->update([
            'is_active' => true,
            'contraindications_reviewed_by' => $reviewer->id,
            'contraindications_reviewed_at' => now(),
        ]);
    }

    /**
     * Congela el contenido actual de este ejercicio, para ser guardado como
     * WorkoutExercise::exercise_snapshot en el momento en que el Training
     * Engine genera una sesión. NUNCA debe usarse para reconstruir
     * retroactivamente lo que un usuario recibió en el pasado — para eso
     * existe el snapshot ya guardado, que es inmutable. Ver
     * docs/DECISIONS.md (Hito 4, inmutabilidad histórica).
     *
     * Hito 8.4: incluye `primary_muscle`/`secondary_muscles` para que las
     * pruebas de aceptación de foco (y una futura auditoría de qué recibió
     * un usuario) puedan verificarse directamente sobre el snapshot
     * persistido, sin depender de que el Exercise original no haya
     * cambiado desde entonces.
     *
     * Hito 9.1: incluye `provider`/`provider_exercise_id` por la misma
     * razón — nunca `video_url` de proveedor (siempre null por diseño,
     * ver `booted()` arriba); el video se resuelve en caliente al enviar,
     * nunca se congela en el histórico.
     *
     * Hito 9.2: incluye la información técnica de ejecución
     * (`important_points`/`common_mistakes`/`breathing_cue`, además de
     * `instructions` ya existente) — `App\Training\Support\
     * ExerciseMessageFormatter` lee EXCLUSIVAMENTE de este snapshot
     * congelado, nunca del `Exercise` en vivo, para que una mejora futura
     * de la técnica de un ejercicio no reescriba retroactivamente lo que
     * un usuario ya recibió.
     *
     * Hito 9.3 (fix post-E2E) — `name`/`instructions`/`important_points`
     * usan la versión en español curada (`*_es`) cuando existe, con el
     * original (típicamente inglés, tal cual lo entrega el proveedor)
     * como fallback si todavía no se generó traducción. Esta es la ÚNICA
     * pieza de este fix con efecto en el envío real: nunca se llama a la
     * IA aquí ni en ningún punto del envío — la traducción ya existe,
     * generada de antemano en curación (ver
     * App\ExerciseCatalog\Curation\ExerciseSpanishContentGenerator),
     * este método solo elige cuál de las dos columnas ya guardadas usar.
     * `ExerciseMessageFormatter`/`TrainingEngine`/`TrainingHandler` no
     * cambian ni saben que esto ocurre — siguen leyendo el snapshot ya
     * congelado como siempre.
     */
    public function toSnapshot(): array
    {
        return [
            'name' => $this->name_es ?? $this->name,
            'instructions' => $this->instructions_es ?? $this->instructions,
            'important_points' => $this->important_points_es ?? $this->important_points,
            'common_mistakes' => $this->common_mistakes,
            'breathing_cue' => $this->breathing_cue,
            'video_url' => $this->video_url,
            'muscle_group' => $this->muscle_group,
            'primary_muscle' => $this->primary_muscle?->value,
            'secondary_muscles' => $this->secondary_muscles,
            'provider' => $this->provider,
            'provider_exercise_id' => $this->provider_exercise_id,
        ];
    }
}
