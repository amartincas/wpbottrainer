<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 15 — tercer origen posible de una fila de `TrainingAccessAudit`,
 * junto a `performed_by` (administrador humano) y `referral_reward_id`
 * (recompensa de Referidos, Hito 13): `auto_provisioned = true` identifica
 * un Trial concedido automáticamente por `AutomaticTrialProvisioner`, sin
 * ningún actor humano ni recompensa de por medio.
 *
 * `TrainingAccessAdministrationService::recordAudit()` amplía su guard
 * estructural de "exactamente uno de dos" a "exactamente uno de tres":
 * `performed_by IS NOT NULL` XOR `referral_reward_id IS NOT NULL` XOR
 * `auto_provisioned = true` — nunca dos a la vez, nunca ninguno. Para un
 * Trial automático: `performed_by = null`, `referral_reward_id = null`,
 * `auto_provisioned = true`. Ver docs/DECISIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_access_audits', function (Blueprint $table) {
            $table->boolean('auto_provisioned')->default(false)->after('referral_reward_id');
        });
    }

    public function down(): void
    {
        Schema::table('training_access_audits', function (Blueprint $table) {
            $table->dropColumn('auto_provisioned');
        });
    }
};
