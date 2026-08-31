<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WorkoutSession (Hito 4): la instancia concreta que un Contact debe
 * realizar (o realizó, u omitió) en una fecha determinada. No existe
 * "Workout" como plantilla separada — decisión aprobada de no crear
 * TrainingPlan en este hito. Ver docs/DECISIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('scheduled');
            $table->timestamp('scheduled_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('generated_by')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_sessions');
    }
};
