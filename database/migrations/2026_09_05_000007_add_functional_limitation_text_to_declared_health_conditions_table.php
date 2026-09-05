<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 5 — preserva el texto LITERAL de una limitación funcional explícita
 * ("no puedo levantar el brazo por encima de la cabeza"), distinto de
 * `original_text` (la condición en sí, ej. "tengo una lesión de hombro").
 * Nullable: la mayoría de declaraciones no incluyen una limitación
 * funcional explícita. Nunca se infiere ni se resume — mismo criterio que
 * `original_text` (Bloque 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('declared_health_conditions', function (Blueprint $table) {
            $table->text('functional_limitation_text')->nullable()->after('original_text');
        });
    }

    public function down(): void
    {
        Schema::table('declared_health_conditions', function (Blueprint $table) {
            $table->dropColumn('functional_limitation_text');
        });
    }
};
