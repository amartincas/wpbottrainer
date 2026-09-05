<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 5 — `health_screening_asked`: único campo nuevo en TrainingProfile
 * para HealthScreeningRequirement. NO significa "la pregunta se presentó
 * una vez" (como physical_stats_asked) — significa "la CONVERSACIÓN de
 * screening ya está cerrada" (pudo requerir 1 o 2 intercambios: pregunta
 * inicial, y si hubo condición sin detalle funcional, una de seguimiento).
 * El estado "¿ya se preguntó el seguimiento?" NO se persiste aparte —se
 * deriva de DeclaredHealthCondition::hasPendingReviewFor(), evitando un
 * segundo campo redundante (ver docs/DECISIONS.md D048).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->boolean('health_screening_asked')->default(false)->after('onboarding_turns');
        });
    }

    public function down(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->dropColumn('health_screening_asked');
        });
    }
};
