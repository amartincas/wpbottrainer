<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 8: único cambio a TrainingAccess — trazabilidad de qué Payment lo
 * originó. TrainingAccessGate/TrainingEngine no cambian en absoluto — este
 * campo es solo informativo (ver docs/DECISIONS.md, D018 y el nuevo D025).
 * Nullable: los accesos otorgados manualmente (ej. el activado en el Hito 7
 * antes de que Payments existiera) no tienen un Payment asociado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_accesses', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('contact_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('training_accesses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });
    }
};
