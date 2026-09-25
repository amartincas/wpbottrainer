<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito C (Sustitución de un ejercicio) — `superseded_by_id`: auto-referencia
 * nullable hacia el `WorkoutExercise` que sustituyó a este, MISMO patrón
 * exacto ya validado por `workout_sessions.superseded_by_id` (Hito B2) —
 * mismo nombre de columna, mismo tipo, mismo `nullOnDelete()`. Se escribe
 * EXCLUSIVAMENTE por `ReplaceWorkoutExerciseService`, nunca en la
 * prescripción normal de `TrainingEngine::decideNextSession()`.
 *
 * NO es única (`unique`): nada impide, a nivel de esquema, que en teoría
 * existan varias filas con el mismo valor — la garantía real de "un
 * original nunca se sustituye dos veces" vive en la revalidación bajo
 * `lockForUpdate()` de `ReplaceWorkoutExerciseService` (superseded_by_id
 * debe seguir NULL en el momento de la transacción), no en un constraint de
 * BD — mismo criterio de disciplina de aplicación ya aceptado para
 * `workout_sessions.superseded_by_id`.
 *
 * `WorkoutSession::workoutExercises()` se modifica en el mismo commit para
 * excluir (`whereNull('superseded_by_id')`) cualquier fila marcada — 100%
 * aditivo: ninguna fila existente hoy tiene ni tendrá este valor poblado
 * salvo por una sustitución real futura, así que ningún consumidor
 * existente (entrega, cierre de sesión, frente activo, historial,
 * progresión) cambia de comportamiento para ninguna sesión/ejercicio ya
 * creado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->foreignId('superseded_by_id')
                ->nullable()
                ->after('delivered_at')
                ->constrained('workout_exercises')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by_id');
        });
    }
};
