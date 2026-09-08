<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 12 — historial append-only de transiciones ADMINISTRATIVAS de
 * `TrainingAccess` (Trial otorgado, Free otorgado, extensión, revocación,
 * reactivación) — nunca de transiciones originadas por un Payment
 * confirmado (esas se auditan implícitamente vía el propio historial de
 * `payments`, que ya tiene `reviewed_by`/`reviewed_at`/`review_note`).
 *
 * Justificación (ver docs/DECISIONS.md): `training_accesses` es una sola
 * fila por contacto, mutable, reutilizada indefinidamente — sin esta
 * tabla, la secuencia real de decisiones administrativas (quién otorgó
 * Trial, quién lo convirtió a Free, quién revocó, quién reactivó) es
 * irrecuperable después del hecho, porque cada acción sobrescribe la fila
 * anterior. Mismo patrón ya usado en este proyecto para "necesito
 * historial, no solo el último valor" — ver `DeclaredHealthCondition`
 * (Bloque 2), que resuelve el mismo problema con una tabla propia,
 * append-only, en vez de un mecanismo genérico.
 *
 * Deliberadamente NO es `AlertLog` — AlertLog queda reservado para
 * alertas/comunicaciones (ver docs/DECISIONS.md), nunca como fuente de
 * verdad de ningún historial de dominio.
 *
 * Sin `tenant_id` propio — se alcanza vía `contact_id`, mismo precedente
 * que `Payment`/`TrainingAccess`. Sin `updated_at` — nunca se edita una
 * fila ya creada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_access_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_access_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // TrainingAccessAuditAction
            $table->foreignId('performed_by')->constrained('users'); // siempre un humano — nunca nullable
            $table->string('previous_status')->nullable(); // null solo si nunca existió TrainingAccess antes
            $table->string('new_status');
            $table->timestamp('previous_expires_at')->nullable();
            $table->timestamp('new_expires_at')->nullable();
            $table->text('reason')->nullable(); // obligatorio a nivel de servicio solo para revoke()
            $table->timestamp('created_at')->useCurrent();

            $table->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_access_audits');
    }
};
