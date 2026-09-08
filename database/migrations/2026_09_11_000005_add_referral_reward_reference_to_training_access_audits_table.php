<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 13 — distinguir estructuralmente una auditoría ADMINISTRATIVA
 * (`performed_by` = un humano real, `is_super_admin`) de una auditoría
 * SISTEMA (consecuencia de una recompensa de Referidos aplicada vía
 * `TrainingAccessAdministrationService::extendByDays()`), sin recurrir a
 * un usuario "sistema" ficticio (activamente engañoso en Filament — se
 * vería como una persona real que nunca actuó).
 *
 * `performed_by` pasa a nullable — es NULL exclusivamente cuando el origen
 * es una recompensa de Referidos. `referral_reward_id` (nullable, FK a
 * `referral_rewards`, `nullOnDelete()` porque el hecho auditado no debe
 * desaparecer si por alguna razón se borrara la recompensa) es la prueba
 * estructural, tipada y consultable de ese origen — nunca un string libre
 * ("source"/"granted_by"), que sería una etiqueta sin garantía
 * referencial. `TrainingAccessAdministrationService::recordAudit()` impone
 * en código la relación exacta: `performed_by IS NULL` si y solo si
 * `referral_reward_id IS NOT NULL` — nunca ambos, nunca ninguno (ver
 * docs/DECISIONS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_access_audits', function (Blueprint $table) {
            $table->foreignId('performed_by')->nullable()->change();
            $table->foreignId('referral_reward_id')->nullable()->after('performed_by')
                ->constrained('referral_rewards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('training_access_audits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referral_reward_id');
            $table->foreignId('performed_by')->nullable(false)->change();
        });
    }
};
