<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito B3 (Preferencias persistentes) — diseño v3 FINAL aprobado. Entidad
 * separada, deliberadamente NO reutiliza `training_restrictions` (Safety es
 * un dominio distinto, con revisión humana obligatoria; una Preference nunca
 * la requiere). Ver docs/DECISIONS.md.
 *
 * Lifecycle (diseño v3, Sección A.1/A.8): UNA sola fila por
 * `(contact_id, preference_key)` durante toda la vida del contacto —
 * create -> revoke -> reactivate reutiliza SIEMPRE la misma fila, nunca crea
 * una segunda. Por eso NO existe `updated_at` (ninguna transición fuera de
 * create/revoke/reactivate cambia esta fila, y esas tres ya tienen su propio
 * timestamp dedicado) ni `source` (en el MVP el único origen posible es
 * `user_message` — una columna con un solo valor real no tiene consumidor).
 *
 * `preference_key` es un string NOT NULL calculado por la capa de aplicación
 * (`TrainingPreferenceRecorder`), formato `"{dimension}:{valor}"` (ej.
 * `"exercise:413"`, `"equipment:dumbbells"`) — evita depender de la
 * semántica de NULL en índices únicos multi-columna (MySQL/Postgres NO
 * tratan NULL=NULL como duplicado, así que un unique directo sobre
 * `(contact_id, dimension, exercise_id, equipment_value)` NO bloquearía dos
 * filas de Equipment con el mismo valor, ambas con `exercise_id=NULL`).
 * `preference_key` nunca es NULL, así que el unique index funciona
 * idénticamente en cualquier motor, sin necesitar índices parciales/filtrados
 * (que MySQL no soporta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('dimension');
            $table->foreignId('exercise_id')->nullable()->constrained('exercises')->nullOnDelete();
            $table->string('equipment_value')->nullable();
            $table->string('preference_key');
            $table->string('status');
            $table->text('original_text');
            $table->timestamp('created_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('reactivated_at')->nullable();

            $table->unique(['contact_id', 'preference_key']);
            $table->index(['contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_preferences');
    }
};
