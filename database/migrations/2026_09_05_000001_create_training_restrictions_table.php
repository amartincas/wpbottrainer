<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito de seguridad de restricciones — contrato canónico
 * (body_region/restriction_type/source/status), fuente NUEVA y
 * prospectiva de restricciones de usuario. Convive con
 * TrainingProfile.restrictions (legacy, texto libre) — ver
 * SafetyRestrictionResolver, que es la única pieza que conoce ambas.
 *
 * `declared_health_condition_id` se deja como columna simple (sin FK
 * todavía) porque esa tabla no existe en este bloque — se conecta con su
 * restricción de clave foránea real cuando se implemente
 * DeclaredHealthCondition, sin necesitar otra migración de esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('body_region');
            $table->string('restriction_type');
            $table->string('source');
            $table->string('status');
            $table->text('original_text');
            $table->unsignedBigInteger('declared_health_condition_id')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_restrictions');
    }
};
