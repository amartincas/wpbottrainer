<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 9.3 (fix post-E2E) — hallazgo real: el contenido técnico
 * (`name`/`instructions`/`important_points`) llega del proveedor
 * exclusivamente en inglés, y no existe ninguna capa de traducción — se
 * entregaba verbatim en inglés al usuario final por WhatsApp.
 *
 * Estas tres columnas son la versión en español, generada por IA en
 * CURACIÓN (acción manual de Filament, nunca en el momento de envío — ver
 * App\ExerciseCatalog\Curation\ExerciseSpanishContentGenerator) y editable
 * después por un humano. Deliberadamente NUEVAS columnas, nunca
 * sobreescribiendo `name`/`instructions`/`important_points`: el contenido
 * original del proveedor se preserva siempre, tanto porque
 * ExerciseImporter lo sigue refrescando en cada re-sync (perdería
 * cualquier traducción si compartieran columna) como para poder auditar/
 * regenerar la traducción sin perder la fuente original.
 *
 * `content_translated_at`: cuándo se generó/editó por última vez la
 * versión en español — comparable a mano contra `synced_at` para que un
 * administrador note si el proveedor actualizó el contenido original
 * después de traducirlo (sin ninguna lógica automática de "stale" — ver
 * docs/DECISIONS.md, fuera de alcance de este hito).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->string('name_es')->nullable()->after('name');
            $table->json('instructions_es')->nullable()->after('instructions');
            $table->json('important_points_es')->nullable()->after('important_points');
            $table->timestamp('content_translated_at')->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn(['name_es', 'instructions_es', 'important_points_es', 'content_translated_at']);
        });
    }
};
