<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H16.1 — `coach_conversation_reinforced`: mismo patrón exacto que
 * `physical_stats_asked`/`health_screening_asked` — un booleano de "esto ya
 * se mostró una vez", nunca reintentado. Significa EXCLUSIVAMENTE "ya se
 * incorporó, al menos una vez, el refuerzo de que el usuario puede
 * conversar libremente con el Coach" — nunca "el usuario conoce todas las
 * capacidades del sistema". Se marca únicamente cuando el contrato JSON de
 * CoachService confirma explícitamente que el refuerzo fue incluido en la
 * respuesta final (ver CoachService::parseJson()), nunca por el solo hecho
 * de haber invocado a CoachService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->boolean('coach_conversation_reinforced')->default(false)->after('health_screening_asked');
        });
    }

    public function down(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->dropColumn('coach_conversation_reinforced');
        });
    }
};
