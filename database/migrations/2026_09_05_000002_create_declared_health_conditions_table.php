<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito de seguridad de restricciones (Bloque 2) — trazabilidad persistente
 * de declaraciones de salud/restricciones, append-only (nunca se
 * sobrescribe una declaración anterior con una nueva). Deliberadamente
 * separada de `training_restrictions` (Bloque 1): una declaración es un
 * HECHO ("el usuario dijo X"), una restricción es una DECISIÓN ("X excluye
 * estos ejercicios") — nunca se confunden ni se fusionan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('declared_health_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->text('original_text');
            $table->foreignId('source_message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->string('category');
            $table->string('suggested_body_region')->nullable();
            $table->string('status');
            $table->foreignId('related_restriction_id')->nullable()->constrained('training_restrictions')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('declared_at');
            $table->timestamps();

            $table->index(['contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('declared_health_conditions');
    }
};
