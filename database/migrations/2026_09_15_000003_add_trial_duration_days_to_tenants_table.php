<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 15 — duración del Trial automático, configurable por Tenant (mismo
 * criterio que `referral_reward_days`: configuración de negocio simple,
 * vive directamente en `tenants`, no en una tabla propia). Default de
 * negocio = 5 días, fijado a nivel de columna — `AutomaticTrialProvisioner`
 * y `TrainingAccessAdministrationService::grantAutomaticTrial()` leen
 * siempre `Tenant::trial_duration_days`, nunca un valor hardcodeado en
 * código de aplicación. Ver docs/DECISIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_duration_days')->default(5)->after('referral_program_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('trial_duration_days');
        });
    }
};
