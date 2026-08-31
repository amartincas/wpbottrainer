<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exercise (Hito 4): catálogo maestro, GLOBAL — no lleva tenant_id a
 * propósito (decisión aprobada: un ejercicio no varía por país/tenant).
 * Nunca se borra físicamente (is_active la retira del catálogo vigente) —
 * ver docs/DECISIONS.md sobre inmutabilidad histórica: WorkoutExercise
 * congela su propio snapshot y no depende de que esta fila siga existiendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('instructions');
            $table->string('video_url');
            $table->string('muscle_group');
            $table->json('equipment_needed')->nullable();
            $table->string('difficulty_level');
            $table->json('contraindications')->nullable();
            $table->string('tracking_type')->default('reps_and_load');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['muscle_group', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
