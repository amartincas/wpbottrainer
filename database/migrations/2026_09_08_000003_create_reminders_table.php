<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 10 — `Reminder`: la programación real, ya confirmada (única o
 * recurrente). `status` representa EXCLUSIVAMENTE el ciclo de vida del
 * envío (pending → sending → sent, o cancelled/failed) — nunca se mezcla
 * con `awaiting_response_until`, que representa únicamente la ventana de
 * continuidad conversacional posterior a un envío ya exitoso (ver D053).
 *
 * `recovery_attempts`: cuenta cuántas veces `reminders:recover-stuck` ha
 * liberado esta fila de `sending` — al llegar a 3 pasa a `failed` en vez de
 * reintentar indefinidamente.
 *
 * `active_lock_contact_id`: mismo mecanismo que `reminder_suggestions` —
 * máximo un Reminder con status `pending`/`sending` por contacto, forzado
 * por un índice único sobre una columna generada, no por una tabla de locks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            $table->string('type'); // 'training_weekly' | 'training_one_off'
            $table->string('status')->default('pending'); // ReminderStatus
            $table->timestamp('fire_at'); // SIEMPRE UTC
            $table->json('recurrence')->nullable(); // null = único
            $table->timestamp('awaiting_response_until')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->foreignId('created_from_suggestion_id')->nullable()
                ->constrained('reminder_suggestions')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedTinyInteger('recovery_attempts')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'contact_id']);
            $table->index(['status', 'fire_at']); // reminders:dispatch-due
        });

        DB::statement("
            ALTER TABLE reminders
            ADD COLUMN active_lock_contact_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (CASE WHEN status IN ('pending','sending') THEN contact_id END) VIRTUAL,
            ADD UNIQUE KEY uq_reminders_active_contact (active_lock_contact_id)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
