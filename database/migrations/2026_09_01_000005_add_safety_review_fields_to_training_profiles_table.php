<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safety Review / Desbloqueo (propuesto en Hito 7.1, implementado en Hito 8
 * junto con Payments para compartir una única convención de "revisión
 * humana" — mismo shape que Payment.reviewed_by/reviewed_at/review_note).
 *
 * `clearSafetyFlag()` (App\Models\TrainingProfile) pasa a exigir quién y
 * por qué — nunca se ejecuta automáticamente ni por el LLM. Ver
 * docs/DECISIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->foreignId('safety_reviewed_by')->nullable()->after('safety_flagged_at')->constrained('users')->nullOnDelete();
            $table->timestamp('safety_reviewed_at')->nullable()->after('safety_reviewed_by');
            $table->text('safety_review_note')->nullable()->after('safety_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('safety_reviewed_by');
            $table->dropColumn(['safety_reviewed_at', 'safety_review_note']);
        });
    }
};
