<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hito B3.1 (Estado conversacional para clarificaciones de preferencias) —
 * entidad separada de `training_preferences`: esta tabla nunca representa
 * una preferencia en sí, solo el estado transitorio "se le preguntó al
 * usuario a cuál ejercicio se refería, todavía no contestó (o contestó de
 * forma ambigua)". Pertenece a `Contact`, igual que `reminder_suggestions`
 * (mismo patrón deliberadamente reutilizado) — sin `tenant_id` propio (se
 * hereda vía `contact_id`, mismo criterio que `training_preferences`).
 *
 * `active_lock_contact_id`: columna generada (virtual) + índice único —
 * MISMO mecanismo ya validado en `reminder_suggestions` (Hito 10) para
 * garantizar "como mucho una PENDING por contacto" a nivel de motor de base
 * de datos, a prueba de dos requests simultáneos. NULL para cualquier status
 * distinto de 'pending', así que un historial de filas resolved/abandoned/
 * expired para el mismo contacto nunca colisiona entre sí ni con una nueva
 * pending.
 *
 * Ningún registro se destruye nunca — resolve/abandon/expire son
 * transiciones de `status` (ver `TrainingPreferenceClarificationRecorder`),
 * nunca un DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_preference_clarifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('dimension');
            // Nullable: el ancla de contexto activo (Camino 1 de IdentityResolver)
            // puede producir una resolución sin candidateTerm, aunque en la
            // práctica `clarify()` (única vía que crea una pending) siempre
            // proviene de Camino 2 con un candidateTerm real — se deja
            // nullable por fidelidad al tipo real de TrainingPreferenceClassification::$candidateTerm.
            $table->string('original_candidate_term')->nullable();
            $table->text('original_text');
            $table->json('presented_options');
            $table->unsignedInteger('total_matches');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            $table->index(['contact_id', 'status']);
        });

        DB::statement('
            ALTER TABLE training_preference_clarifications
            ADD COLUMN active_lock_contact_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (CASE WHEN status = \'pending\' THEN contact_id END) VIRTUAL,
            ADD UNIQUE KEY uq_training_pref_clarifications_pending_contact (active_lock_contact_id)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('training_preference_clarifications');
    }
};
