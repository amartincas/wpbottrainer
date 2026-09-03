<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 9.1/9.2 — información técnica de ejecución, ver docs/DECISIONS.md.
 *
 * `instructions` cambia de `text` (una oración libre) a `json` (array de
 * pasos) — necesario para que ExerciseMessageFormatter pueda iterar los
 * pasos individualmente en vez de un bloque de texto ya unido.
 *
 * DATO REAL A PRESERVAR: el único Exercise real hoy ("Plancha", demo de
 * Hito 7) tiene `instructions` como una oración plana, NO JSON válido —
 * cambiar el tipo de columna directamente la habría corrompido (MariaDB
 * valida JSON al tipar la columna). Por eso el backfill (envolver el
 * string existente en un array de un elemento) corre ANTES del cambio de
 * tipo, nunca al revés.
 *
 * `important_points`: viene del proveedor (YMove: `importantPoints[]`),
 * se refresca en cada re-sync igual que el resto de metadata.
 * `common_mistakes`/`breathing_cue`: NINGÚN proveedor auditado los
 * provee — solo existen si un humano los cura. Por eso
 * `ExerciseImporter` nunca los toca en un re-sync (mismo criterio de
 * preservación que `contraindications`), y nunca se inventan
 * automáticamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "UPDATE exercises SET instructions = JSON_ARRAY(instructions) ".
            "WHERE instructions IS NOT NULL AND JSON_VALID(instructions) = 0"
        );

        Schema::table('exercises', function (Blueprint $table) {
            $table->json('important_points')->nullable()->after('description');
            $table->json('common_mistakes')->nullable()->after('important_points');
            $table->text('breathing_cue')->nullable()->after('common_mistakes');
        });

        Schema::table('exercises', function (Blueprint $table) {
            $table->json('instructions')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->text('instructions')->nullable(false)->change();
        });

        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn(['important_points', 'common_mistakes', 'breathing_cue']);
        });
    }
};
