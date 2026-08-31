<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ExerciseSet (Hito 4): cada serie realmente ejecutada — reps/carga/duración
 * pueden variar de una serie a otra (ej. 10x40kg, 10x45kg, 8x50kg), algo que
 * un ExerciseLog con campos escalares no podía representar. actual_reps,
 * actual_load y actual_duration_seconds son todos nullable porque un mismo
 * ejercicio es o bien por repeticiones/carga o bien por tiempo
 * (Exercise.tracking_type), nunca ambos a la vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_log_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('set_number');
            $table->unsignedTinyInteger('actual_reps')->nullable();
            $table->decimal('actual_load', 6, 2)->nullable();
            $table->unsignedInteger('actual_duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['exercise_log_id', 'set_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_sets');
    }
};
