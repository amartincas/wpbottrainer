<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito B2 (Nueva rutina durante sesión activa) — `superseded_by_id`:
 * auto-referencia nullable hacia la `WorkoutSession` que reemplazó a esta.
 * Se escribe EXCLUSIVAMENTE por `ReplaceWorkoutSessionService`, nunca en
 * ningún otro punto. `nullOnDelete()` (mismo criterio que `exercise_id` en
 * `workout_exercises`): si la sesión nueva alguna vez se elimina físicamente
 * (no debería pasar en operación normal), la sesión reemplazada sobrevive
 * intacta, solo pierde el vínculo de trazabilidad — nunca se arrastra en
 * cascada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_sessions', function (Blueprint $table) {
            $table->foreignId('superseded_by_id')
                ->nullable()
                ->after('prescription_context_snapshot')
                ->constrained('workout_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workout_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by_id');
        });
    }
};
