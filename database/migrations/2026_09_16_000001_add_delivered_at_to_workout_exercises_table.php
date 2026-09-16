<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-A (Nudge por ejercicio no reportado) — `delivered_at`: momento exacto
 * en que el mensaje de técnica de ESTE ejercicio se entregó al usuario
 * (ver `TrainingHandler::deliverExercise()`). Distinto de `created_at`
 * (todos los `WorkoutExercise` de una sesión se crean juntos al decidir la
 * sesión — `TrainingEngine::decideNextSession()` — mucho antes de que cada
 * uno se entregue realmente, dado que H16.2 los entrega uno a la vez).
 *
 * Nullable, sin backfill: igual criterio que `prescription_context_snapshot`
 * — no hay forma de reconstruir con certeza cuándo se entregó un ejercicio
 * ya existente antes de este cambio, así que se deja `null` en vez de
 * inventar una fecha aproximada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('exercise_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->dropColumn('delivered_at');
        });
    }
};
