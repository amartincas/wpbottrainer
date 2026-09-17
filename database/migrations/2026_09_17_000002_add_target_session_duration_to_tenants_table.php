<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duración objetivo APROXIMADA de una sesión de entrenamiento — configuración
 * de negocio simple por Tenant, mismo criterio ya establecido por
 * `exercise_nudge_after_minutes`/`trial_duration_days`/`referral_reward_days`:
 * vive directamente en `tenants`, no en una tabla propia.
 *
 * `default(30)` es intencional, no arbitrario: con la heurística aprobada de
 * `DurationEstimator` (`AVERAGE_SET_EXECUTION_SECONDS = 120`) y los
 * `GOAL_DEFAULTS['general_fitness']` actuales de `TrainingEngine`
 * (sets=3, rest_seconds=60 → 9 min/ejercicio), 30 minutos reproduce
 * exactamente los 3 ejercicios por sesión que el sistema entregaba antes de
 * este cambio — todos los Tenants existentes conservan el comportamiento
 * actual sin necesitar ningún backfill manual.
 *
 * NO es un máximo estricto ni una duración exacta — ver
 * App\Training\Engine\TrainingEngine::exercisesForTargetDuration() y
 * App\Training\Support\SessionIntroComposer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('target_session_duration_minutes')
                ->default(30)
                ->after('exercise_nudge_after_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('target_session_duration_minutes');
        });
    }
};
