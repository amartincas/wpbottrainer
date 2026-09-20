<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral Introduction — `referral_introduction_sent_at` vive en
 * `contacts`, no en `training_profiles`: es estado de una comunicación/
 * proactividad del PROGRAMA de Referral hacia este Contact, no un dato del
 * perfil de entrenamiento (mismo criterio de separación ya usado en el
 * proyecto: `customer_name`/identidad vive en `Contact`, mientras que
 * progreso/preferencias de Training viven en `TrainingProfile` — ver
 * docblock de `NameRequirement`, Hito 8.3).
 *
 * Nullable, sin default más allá de NULL, sin backfill — mismo criterio ya
 * establecido para todo marcador "de aquí en adelante" de este proyecto:
 * los Contacts ya existentes antes de este cambio simplemente nunca
 * recibieron la introducción todavía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('referral_introduction_sent_at')->nullable()->after('bot_active');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('referral_introduction_sent_at');
        });
    }
};
