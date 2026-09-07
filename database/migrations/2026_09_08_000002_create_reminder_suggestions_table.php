<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 10 — `ReminderSuggestion`: propuesta efímera, previa a la
 * confirmación explícita del usuario. Nunca crea un `Reminder` por sí sola.
 *
 * `active_lock_contact_id`: columna generada (virtual), NO una tabla de
 * locks — resuelve "máximo una ReminderSuggestion `pending` por contacto"
 * a nivel de motor de base de datos, a prueba de dos requests simultáneos.
 * Validado empíricamente contra MySQL 8 (motor real de desarrollo/test) antes
 * de esta migración; sintaxis estándar SQL (`GENERATED ALWAYS AS ... VIRTUAL`),
 * también soportada por MariaDB 10.2+ (staging/producción corren MariaDB 11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            $table->string('origin');            // ReminderSuggestionOrigin: user_request | proactive
            $table->string('trigger_reason')->nullable(); // solo si origin=proactive; libre, mismo criterio que Alert::category
            $table->string('proposed_type');      // 'training_weekly' | 'training_one_off'
            $table->json('proposed_params');      // ya resuelto por ReminderTimeResolver — nunca texto crudo
            $table->string('status')->default('pending'); // ReminderSuggestionStatus
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'contact_id']);
        });

        // Columna generada + índice único — MariaDB/MySQL tratan NULL como
        // "no colisiona con nada", así que solo status='pending' bloquea un
        // segundo intento para el mismo contacto; cualquier otro estado
        // convive libremente.
        DB::statement("
            ALTER TABLE reminder_suggestions
            ADD COLUMN active_lock_contact_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN contact_id END) VIRTUAL,
            ADD UNIQUE KEY uq_reminder_suggestions_pending_contact (active_lock_contact_id)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_suggestions');
    }
};
