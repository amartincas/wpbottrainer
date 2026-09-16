<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-A (Nudge por ejercicio no reportado) — configuración de negocio simple
 * por Tenant, mismo criterio ya establecido por `referral_reward_days`/
 * `referral_program_enabled` y `trial_duration_days`: vive directamente en
 * `tenants`, no en una tabla de "settings" propia (ver esas migraciones).
 *
 * `exercise_nudge_after_minutes` = 30 es solo el valor inicial de la
 * columna — cada tenant lo ajusta libremente desde Filament sin ningún
 * deploy. `App\Training\Support\UnreportedExerciseDetector` lee siempre
 * `Tenant::exercise_nudge_after_minutes`/`exercise_nudge_enabled`, nunca un
 * valor hardcodeado ni de `.env`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('exercise_nudge_enabled')->default(true)->after('trial_duration_days');
            $table->unsignedSmallInteger('exercise_nudge_after_minutes')->default(30)->after('exercise_nudge_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['exercise_nudge_enabled', 'exercise_nudge_after_minutes']);
        });
    }
};
