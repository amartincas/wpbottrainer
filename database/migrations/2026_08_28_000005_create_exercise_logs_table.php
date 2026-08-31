<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ExerciseLog (Hito 4): cabecera de la ejecución real de un WorkoutExercise
 * — RPE general y notas. El detalle serie a serie vive en ExerciseSet.
 * 1:0..1 con WorkoutExercise (no todo ejercicio prescrito llega a tener log,
 * ej. si se omite la sesión).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_exercise_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rpe')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('logged_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_logs');
    }
};
