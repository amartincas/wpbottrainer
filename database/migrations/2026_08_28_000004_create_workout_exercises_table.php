<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WorkoutExercise (Hito 4): lo que el Training Engine prescribió Y lo que
 * literalmente se mostró al usuario (exercise_snapshot) — inmutable desde su
 * creación (ver docs/DECISIONS.md, requisito obligatorio de Hito 4).
 *
 * exercise_id es nullOnDelete (no cascade): si un Exercise alguna vez se
 * elimina físicamente (no debería pasar, is_active es el mecanismo normal
 * de retiro), el historial de WorkoutExercise sobrevive intacto gracias al
 * snapshot — exercise_id solo sirve para trazabilidad/analítica, nunca para
 * reconstruir retroactivamente el contenido histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('order');
            $table->unsignedTinyInteger('prescribed_sets')->nullable();
            $table->unsignedTinyInteger('prescribed_reps')->nullable();
            $table->decimal('prescribed_load', 6, 2)->nullable();
            $table->unsignedInteger('prescribed_duration_seconds')->nullable();
            $table->unsignedSmallInteger('rest_seconds')->nullable();
            $table->json('exercise_snapshot');
            $table->timestamps();

            $table->index(['workout_session_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_exercises');
    }
};
