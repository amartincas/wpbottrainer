<?php

namespace App\Models;

use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'contact_id',
    'status',
    'scheduled_at',
    'completed_at',
    'generated_by',
    'prescription_context_snapshot',
    // Hito B2 — escrito EXCLUSIVAMENTE por ReplaceWorkoutSessionService,
    // nunca en la creación normal de TrainingEngine::decideNextSession().
    'superseded_by_id',
])]
class WorkoutSession extends Model
{
    use HasFactory;

    /**
     * Bloque 3 — congelado UNA SOLA VEZ por `TrainingEngine::decideNextSession()`
     * en el momento de creación (ver `TrainingProfile::toPrescriptionContextSnapshot()`).
     * Inmutable por convención — igual que `WorkoutExercise.exercise_snapshot` —
     * ningún otro punto del código debe reescribirlo. `null` en sesiones
     * creadas antes de este bloque: nunca se rellena retroactivamente,
     * porque no hay forma de reconstruir con certeza el contexto real usado
     * en aquel momento (ver docs/DECISIONS.md).
     *
     * Fundación Temporal (D046): su clave `generated_at` es `prescribed_at`
     * conceptual — el instante de la decisión, NUNCA el de entrega por
     * WhatsApp — distinto de `scheduled_at` de esta misma fila (el instante
     * para el que la sesión está prevista), aunque hoy coincidan porque
     * `TrainingEngine` no soporta programación anticipada.
     */

    protected function casts(): array
    {
        return [
            'status' => WorkoutSessionStatus::class,
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'prescription_context_snapshot' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function workoutExercises(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class)->orderBy('order');
    }

    /**
     * Corrección post-incidente de staging (#33, hito R1/R2/R3) — la ÚNICA
     * fuente de "qué está viendo/resolviendo el usuario ahora mismo"
     * (FRONT EXERCISE). El WorkoutExercise de mayor `order` entre los ya
     * entregados (`delivered_at !== null`) — nunca `exerciseLog`, nunca
     * `isResolvedForSessionProgression()`, nunca "primer Main sin log".
     *
     * La entrega es estrictamente secuencial (`TrainingHandler` nunca
     * entrega fuera de `order`, nunca entrega dos ejercicios a la vez sin
     * que el usuario resuelva/confirme el anterior), así que en cualquier
     * instante hay como máximo UN WorkoutExercise "entregado y todavía sin
     * resolver" — y es siempre el de mayor `order` entre los entregados.
     *
     * `null` si nada se ha entregado todavía (sesión recién creada, antes
     * de la primera entrega) — en la práctica esto es transitorio: la
     * primera entrega ocurre de forma síncrona en la misma creación de la
     * sesión (ver `TrainingHandler::handle()`).
     *
     * Requiere `workoutExercises` cargado o cargable (misma relación,
     * `orderBy('order')` ya garantizado por `workoutExercises()`).
     */
    public function frontExercise(): ?WorkoutExercise
    {
        return $this->workoutExercises
            ->filter(fn (WorkoutExercise $we) => $we->delivered_at !== null)
            ->sortByDesc('order')
            ->first();
    }

    /**
     * Corrección post-incidente de staging (#33) — responde EXCLUSIVAMENTE
     * "¿qué WorkoutExercise todavía no se le ha mostrado al usuario?"
     * (NEXT TO DELIVER) — nunca "¿qué está resuelto?" (esa es
     * `WorkoutExercise::isResolvedForSessionProgression()`, una pregunta
     * distinta). Deliberadamente NO usa `exerciseLog`/`historicalOutcome()`/
     * ninguna noción de "unreported": un WorkoutExercise ya entregado
     * NUNCA debe volver a aparecer aquí, sin importar si ya fue
     * reportado/confirmado o no — evita exactamente el bug del incidente
     * (un Preparation/Cooldown ya entregado, que nunca tiene `exerciseLog`,
     * siendo re-seleccionado como "siguiente a entregar").
     */
    public function nextUndeliveredExercise(): ?WorkoutExercise
    {
        return $this->workoutExercises->first(fn (WorkoutExercise $we) => $we->delivered_at === null);
    }

    /**
     * Hito B2 (Nueva rutina durante sesión activa) — la `WorkoutSession`
     * NUEVA que reemplazó a esta (`superseded_by_id`), si esta sesión fue
     * reemplazada por `ReplaceWorkoutSessionService`. `null` en cualquier
     * otro caso (incluida cualquier sesión creada antes de este hito).
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * Hito B2 — inversa de `supersededBy()`: la `WorkoutSession` VIEJA que
     * esta sesión reemplazó, si esta sesión fue creada por
     * `ReplaceWorkoutSessionService` como reemplazo de otra. `null` para
     * cualquier sesión creada por el flujo normal de
     * `TrainingEngine::decideNextSession()` sin reemplazo.
     */
    public function supersededSession(): HasOne
    {
        return $this->hasOne(self::class, 'superseded_by_id');
    }
}
