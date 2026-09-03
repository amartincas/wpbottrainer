<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 9.1 — capa de contenido multi-proveedor. `provider` es un string
 * simple (nunca un enum de dominio ni una tabla `providers`) validado en
 * escritura contra App\ExerciseCatalog\ProviderRegistry — agregar un
 * proveedor nuevo es una implementación + una línea de config, nunca una
 * migración. NULLs múltiples en (provider, provider_exercise_id) conviven
 * sin conflicto en el índice único — los ejercicios manuales/curados
 * (provider=null, ej. la "Plancha" demo de Hito 7) siguen funcionando
 * exactamente igual.
 *
 * `contraindications_reviewed_at`/`_by` (mismo patrón que
 * TrainingProfile.safety_reviewed_at/_by): un ejercicio de proveedor nunca
 * puede activarse (Exercise::activate()) sin que un humano haya revisado
 * sus contraindicaciones — ver docs/DECISIONS.md.
 *
 * `provider_metadata` es el payload crudo original — solo auditoría/debug,
 * nunca leído por TrainingEngine/Normalizer después del import.
 *
 * Hallazgo real de esta migración: `video_url`, `difficulty_level` e
 * `instructions` NUNCA fueron nullable desde el Hito 4 — nada, hasta
 * ahora, había intentado insertar un Exercise sin ellos. Con contenido
 * real de proveedor eso deja de sostenerse: `video_url` DEBE ser null
 * para todo `Exercise` con `provider` (la URL nunca se persiste), YMove
 * ya devolvió `difficulty: null` en la propia prueba técnica real
 * (Barbell Hip Thrust) — `TrainingEngine::difficultyMatchRank()` ya lo
 * tolera en código, pero la columna en sí lo habría rechazado a nivel de
 * BD — y algunos ejercicios de proveedor no traen `instructions` en
 * absoluto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('id');
            $table->string('provider_exercise_id')->nullable()->after('provider');
            $table->json('provider_metadata')->nullable()->after('provider_exercise_id');
            $table->text('description')->nullable()->after('name');
            $table->json('exercise_type')->nullable()->after('movement_pattern');
            $table->unsignedInteger('video_duration_seconds')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('contraindications_reviewed_at')->nullable();
            $table->foreignId('contraindications_reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['provider', 'provider_exercise_id']);
        });

        Schema::table('exercises', function (Blueprint $table) {
            $table->string('video_url')->nullable()->change();
            $table->string('difficulty_level')->nullable()->change();
            $table->text('instructions')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->string('video_url')->nullable(false)->change();
            $table->string('difficulty_level')->nullable(false)->change();
            $table->text('instructions')->nullable(false)->change();
        });

        Schema::table('exercises', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_exercise_id']);
            $table->dropConstrainedForeignId('contraindications_reviewed_by');
            $table->dropColumn([
                'provider',
                'provider_exercise_id',
                'provider_metadata',
                'description',
                'exercise_type',
                'video_duration_seconds',
                'synced_at',
                'contraindications_reviewed_at',
            ]);
        });
    }
};
