<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TrainingProfile (Hito 4): características y preferencias de entrenamiento
 * del usuario. 1:1 con Contact — no denormaliza tenant_id, igual que
 * product_images no lo denormaliza respecto a products (se escala vía la
 * relación con Contact, que ya es tenant-scoped). Ver docs/DECISIONS.md.
 *
 * access_status NO vive aquí a propósito (ver TrainingAccess) — mezclar
 * entitlement comercial con características físicas fue explícitamente
 * rechazado en el diseño de Hito 4.
 *
 * goal/experience_level nullable desde el Hito 5: el onboarding conversacional
 * es progresivo (el usuario puede responder parcialmente y continuar en un
 * turno posterior), así que la fila debe poder existir incompleta. Editada en
 * la migración histórica (no una migración aditiva nueva) porque el proyecto
 * todavía no tiene datos de producción — mismo criterio que D011.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('goal')->nullable();
            $table->string('experience_level')->nullable();
            $table->json('available_equipment')->nullable();
            $table->json('restrictions')->nullable();
            $table->unsignedTinyInteger('sessions_per_week')->nullable();
            $table->string('split_type')->nullable();
            $table->string('next_focus')->nullable();
            $table->string('safety_status')->default('normal');
            $table->string('safety_flag_reason')->nullable();
            $table->timestamp('safety_flagged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_profiles');
    }
};
