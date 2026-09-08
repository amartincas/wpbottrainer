<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 13 — identidad pública de un Contact como referente. Un código por
 * Contact, generado perezosamente (la primera vez que pide su invitación),
 * estable (no regenerable en este hito). Sin `tenant_id` propio — se
 * alcanza vía `contact_id` (mismo precedente que Payment/TrainingAccess/
 * TrainingAccessAudit). `code` es único GLOBALMENTE (no por tenant) —
 * simplifica el índice y el aislamiento real ocurre al momento de canjear
 * el código (validación cross-tenant explícita), no en su generación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};
