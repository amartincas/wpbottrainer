<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 11 — snapshot histórico de la membresía comprada, congelado en el
 * propio `Payment` en el momento de crearlo:
 *
 * - `membership_plan_id` (nullable, `nullOnDelete`): procedencia/
 *   trazabilidad ÚNICAMENTE — qué opción del catálogo se eligió. Nunca se
 *   consulta para decidir cuánto conceder; si el plan cambia de precio,
 *   se desactiva, o se borra, este `Payment` sigue representando la
 *   compra original exactamente como ocurrió.
 * - `membership_months` (nullable): el snapshot real que gobierna
 *   `PaymentConfirmationService::grantAccess()`. Nullable a propósito —
 *   todo `Payment` creado ANTES de este hito no tiene valor aquí, y debe
 *   seguir concediendo 1 mes exactamente como lo hacía antes (fallback
 *   `LEGACY_DEFAULT_MONTHS` en código, nunca un backfill de datos
 *   históricos). `Payment.amount`/`Payment.currency` (ya existentes) ya
 *   cumplen el rol de snapshot de precio — no necesitan columna nueva,
 *   solo cambia de dónde se pueblan al crear el Payment (del
 *   `MembershipPlan` elegido, en vez de `Tenant.monthly_price`).
 *
 * `amount`/`currency` pasan a ser nullable: un Payment ahora nace en el
 * momento en que se elige el MÉTODO de pago, ANTES de saber qué membresía
 * se va a comprar (`Payment::needsPlanSelection()`) — durante esa ventana
 * corta no hay ningún precio que registrar todavía. Se rellenan tan
 * pronto se selecciona el plan (`PaymentHandler::applyPlanSelection()`),
 * nunca quedan nulos una vez que el Payment sale de ese estado transitorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('membership_plan_id')->nullable()->after('contact_id')
                ->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('membership_months')->nullable()->after('membership_plan_id');
            $table->decimal('amount', 12, 2)->nullable()->change();
            $table->string('currency')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membership_plan_id');
            $table->dropColumn('membership_months');
            $table->decimal('amount', 12, 2)->nullable(false)->change();
            $table->string('currency')->nullable(false)->change();
        });
    }
};
