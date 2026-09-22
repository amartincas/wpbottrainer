<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito A (Entry/Domain Fallback) — `primary_domain` es la única señal
 * explícita de identidad de dominio que un Tenant declara. Nullable, sin
 * default forzado: ningún tenant existente cambia de comportamiento hasta
 * que se configure explícitamente — sin backfill automático, y
 * deliberadamente NUNCA inferido de `Product::count()`, `trial_duration_days`,
 * `exercise_nudge_enabled` ni ninguna otra feature (ver docs/DECISIONS.md,
 * auditoría de Hito A).
 *
 * Único valor real hoy: `'training'`. `null` preserva el comportamiento
 * actual exacto (FallbackChat como default universal) — no se introduce un
 * valor formal `'ecommerce'`: ese flujo no es un dominio con las mismas
 * garantías que Training, es la ausencia de uno.
 *
 * Consultada ÚNICAMENTE por App\Training\Support\TrainingDomainFallbackClaim
 * — Core (Router/Dispatcher/PreRoutingScreener/ProcessWhatsAppMessage)
 * nunca la lee directamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('primary_domain')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('primary_domain');
        });
    }
};
