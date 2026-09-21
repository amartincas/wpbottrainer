<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito R1/R2/R3 — `phase` distingue preparación/bloque principal/
 * finalización dentro de una `WorkoutSession`. `DEFAULT 'main'` es la
 * ÚNICA fuente de compatibilidad con los registros existentes — se aplica
 * a nivel de columna de MySQL, así que las filas ya existentes reciben
 * `'main'` automáticamente en el momento del `ALTER TABLE`, sin ningún
 * `UPDATE` explícito ni backfill de datos (mismo criterio ya establecido
 * en este proyecto para columnas nuevas "de aquí en adelante").
 *
 * Nunca nullable: todo `WorkoutExercise`, histórico o nuevo, tiene una
 * fase real — a diferencia de columnas como `delivered_at` (que sí admite
 * "todavía no ocurrió"), `phase` siempre tiene un valor conocido en el
 * momento de la creación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->string('phase')->default('main')->after('order');
        });
    }

    public function down(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->dropColumn('phase');
        });
    }
};
