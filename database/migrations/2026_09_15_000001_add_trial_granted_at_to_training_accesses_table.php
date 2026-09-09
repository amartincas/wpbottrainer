<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 15 — `trial_granted_at`: marca histórica INMUTABLE de "¿este Contact
 * recibió alguna vez un Trial?" (manual O automático — una sola fuente de
 * verdad para ambos, ver `TrainingAccessAdministrationService::
 * markTrialGrantedIfFirstTime()`). Se fija una única vez, en la primera
 * concesión; nunca se sobreescribe después. Es, junto con "nunca tuvo un
 * Payment confirmado", la base de la regla de elegibilidad de
 * `AutomaticTrialProvisioner` (ver docs/DECISIONS.md).
 *
 * Backfill (dos pasos, con precedencia — ver docs/DECISIONS.md):
 *
 * 1. PRIORITARIO: si existe una auditoría histórica de concesión de Trial
 *    (`training_access_audits.action = 'trial_granted'`) para esta fila, se
 *    usa la fecha de la más antigua — es la fuente más precisa disponible.
 * 2. FALLBACK, solo para lo que el paso 1 no resolvió: si el estado actual
 *    todavía es `trial` y no hay evidencia de auditoría, se usa `created_at`
 *    de la propia fila como aproximación histórica — documentado
 *    explícitamente como aproximado, nunca como una fecha exacta.
 *
 * Límite aceptado y documentado: una fila cuyo estado YA cambió (pasó a
 * Active/Revoked/Free) SIN evidencia de auditoría queda sin backfill
 * (permanece NULL). Es inofensivo si esa historia incluye un Payment
 * confirmado (la otra mitad de la regla de elegibilidad ya la excluye); el
 * único caso residual real es un Trial histórico sin auditoría, revocado
 * sin haber pagado nunca — revisable manualmente vía los propios
 * `TrainingAccessAudit` existentes si se identifica un caso real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_accesses', function (Blueprint $table) {
            $table->timestamp('trial_granted_at')->nullable()->after('granted_at');
        });

        // Paso 1 (prioritario): reconstruir desde la auditoría histórica más
        // antigua de concesión de Trial, si existe.
        DB::statement(<<<'SQL'
            UPDATE training_accesses ta
            JOIN (
                SELECT training_access_id, MIN(created_at) AS first_trial_granted_at
                FROM training_access_audits
                WHERE action = 'trial_granted'
                GROUP BY training_access_id
            ) audit_first ON audit_first.training_access_id = ta.id
            SET ta.trial_granted_at = audit_first.first_trial_granted_at
            WHERE ta.trial_granted_at IS NULL
        SQL);

        // Paso 2 (fallback — solo sobre lo que el paso 1 dejó sin resolver):
        // sin evidencia de auditoría, pero el estado actual sigue siendo
        // 'trial' — created_at de la propia fila como aproximación.
        DB::table('training_accesses')
            ->where('status', 'trial')
            ->whereNull('trial_granted_at')
            ->update(['trial_granted_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('training_accesses', function (Blueprint $table) {
            $table->dropColumn('trial_granted_at');
        });
    }
};
