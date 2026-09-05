<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 4 — `onboarding_turns`: cuenta cuántas interacciones se han
 * procesado mientras el onboarding estaba incompleto (una por mensaje del
 * usuario que cae dentro del flujo de onboarding, nunca por pregunta
 * individual, ni por llamada de IA). La política de turnos progresivos
 * (App\Training\Onboarding\OnboardingRequirementRegistry::secondaryOpportunisticFor())
 * la usa para decidir cuándo empezar a invitar, de forma no bloqueante, a
 * responder un requirement oportunista (ej. `primary_focus`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->unsignedInteger('onboarding_turns')->default(0)->after('physical_stats_asked');
        });
    }

    public function down(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->dropColumn('onboarding_turns');
        });
    }
};
