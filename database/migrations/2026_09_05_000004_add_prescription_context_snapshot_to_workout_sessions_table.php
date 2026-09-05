<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 3 — `prescription_context_snapshot`: contexto congelado en el
 * instante exacto en que `TrainingEngine::decideNextSession()` prescribió
 * esta sesión (goal/experience_level/focus/split/ubicación/equipamiento/
 * restricciones activas), para poder auditar retrospectivamente qué se
 * usó realmente, aunque el `TrainingProfile` cambie después.
 *
 * NULLABLE deliberadamente: las filas de `workout_sessions` creadas antes
 * de este bloque no tienen (ni pueden reconstruir con certeza) este dato
 * — quedan en `null` para siempre, sin backfill ni inferencia desde el
 * `TrainingProfile` actual (ver docs/DECISIONS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_sessions', function (Blueprint $table) {
            $table->json('prescription_context_snapshot')->nullable()->after('generated_by');
        });
    }

    public function down(): void
    {
        Schema::table('workout_sessions', function (Blueprint $table) {
            $table->dropColumn('prescription_context_snapshot');
        });
    }
};
