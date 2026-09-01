<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 8.3 / Hito 8.2 Parte 13 — dato a empezar a recopilar desde MVP para
 * aprendizaje futuro: por qué un ejercicio no se realizó, cuando el usuario
 * lo indica o insinúa. Nunca inventado por la IA — null si no se menciona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercise_logs', function (Blueprint $table) {
            $table->string('skip_reason')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('exercise_logs', function (Blueprint $table) {
            $table->dropColumn('skip_reason');
        });
    }
};
