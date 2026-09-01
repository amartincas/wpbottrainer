<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 8.4 — metadata de foco muscular, más fina que `muscle_group` (6
 * valores gruesos, usado por la rotación de continuidad existente,
 * TrainingEngine::ROTATIONS — sin cambios). `primary_muscle`/
 * `secondary_muscles` usan el vocabulario cerrado de
 * App\Training\Enums\MuscleFocus — es lo que permite comparar
 * directamente contra TrainingProfile.primary_focus/secondary_focus.
 *
 * `movement_pattern` queda deliberadamente sin consumidor activo en este
 * hito — se agrega ahora para que el catálogo real del Hito 9 no requiera
 * otra migración; el scoring de Hito 8.4 no lo usa todavía.
 *
 * Ambas columnas nullable — NO se siembra ningún dato en producción con
 * esta migración (el único ejercicio real de demo queda con estos campos
 * en null hasta que Hito 9 cargue contenido real).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->string('primary_muscle')->nullable()->after('muscle_group');
            $table->json('secondary_muscles')->nullable()->after('primary_muscle');
            $table->string('movement_pattern')->nullable()->after('secondary_muscles');
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn(['primary_muscle', 'secondary_muscles', 'movement_pattern']);
        });
    }
};
