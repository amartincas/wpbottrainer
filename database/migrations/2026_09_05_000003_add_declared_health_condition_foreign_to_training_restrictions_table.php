<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito de seguridad de restricciones (Bloque 2) — completa la FK que el
 * Bloque 1 dejó preparada como columna simple (sin restricción real) en
 * `training_restrictions.declared_health_condition_id`, porque
 * `declared_health_conditions` no existía todavía en ese momento. No
 * toca ninguna otra columna de la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_restrictions', function (Blueprint $table) {
            $table->foreign('declared_health_condition_id')
                ->references('id')->on('declared_health_conditions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('training_restrictions', function (Blueprint $table) {
            $table->dropForeign(['declared_health_condition_id']);
        });
    }
};
