<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito D (Bodyweight + carga + tracking + cargas inválidas, diseño formal
 * v2 aprobado, fase D1) — `load_modality`: clasificación de dominio,
 * ORTOGONAL a `tracking_type`, sobre si un `Exercise` espera carga externa
 * reportable (`Equipment\LoadModality::Required`) o nunca la espera
 * (`::None`, bodyweight puro). Vocabulario cerrado, ver
 * App\Training\Enums\LoadModality.
 *
 * `nullable()`, SIN default — `NULL` significa exclusivamente "todavía sin
 * clasificar", nunca "None" ni "Required". Esta migración NO ejecuta
 * ningún backfill: las 1070 filas existentes del catálogo (60 activas)
 * quedan `NULL` a propósito. El fallback "NULL se comporta como Required
 * durante la validación de reportes" es responsabilidad EXCLUSIVA de la
 * fase D3 (`ExerciseSetValidator`, todavía no implementada) — nunca de
 * este esquema ni del modelo `Exercise`. La curación real (fijar `none`/
 * `required` ejercicio por ejercicio) queda fuera de alcance de D1.
 *
 * 100% aditivo — ninguna fila existente cambia de significado, ningún
 * consumidor actual lee esta columna todavía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->string('load_modality')->nullable()->after('tracking_type');
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn('load_modality');
        });
    }
};
