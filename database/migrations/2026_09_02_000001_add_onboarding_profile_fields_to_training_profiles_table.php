<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 8.3 — ampliación del perfil de onboarding, aprobada explícitamente:
 * nombre (reutiliza Contact.customer_name, sin columna aquí), datos físicos
 * (age/sex/weight_kg/height_cm — capturados desde MVP pero NUNCA bloqueantes
 * para isOnboardingComplete(), ver TrainingProfile::firstMissingOnboardingField()),
 * training_location (SÍ bloqueante, resuelve la ambigüedad de equipo del
 * Hito 8.2) y equipment_fully_equipped (representación explícita de
 * "disponibilidad amplia/completa" sin enumerar, ver docs/DECISIONS.md).
 *
 * `physical_stats_asked` es el mecanismo de "preguntar una sola vez, aceptar
 * cualquier respuesta (incluida ninguna)" — no es un dato del usuario, es
 * estado de la conversación de onboarding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('age')->nullable()->after('sessions_per_week');
            $table->string('sex')->nullable()->after('age');
            $table->decimal('weight_kg', 5, 2)->nullable()->after('sex');
            $table->unsignedSmallInteger('height_cm')->nullable()->after('weight_kg');
            $table->boolean('physical_stats_asked')->default(false)->after('height_cm');
            $table->string('training_location')->nullable()->after('physical_stats_asked');
            $table->boolean('equipment_fully_equipped')->default(false)->after('available_equipment');
        });
    }

    public function down(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'age', 'sex', 'weight_kg', 'height_cm',
                'physical_stats_asked', 'training_location', 'equipment_fully_equipped',
            ]);
        });
    }
};
