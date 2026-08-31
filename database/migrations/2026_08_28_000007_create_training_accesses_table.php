<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TrainingAccess (Hito 4): entitlement/acceso vigente al servicio de
 * Training — deliberadamente separado de TrainingProfile (características
 * físicas != estado comercial). NO es Subscription/Payment/Invoice/
 * Enrollment — solo responde "¿tiene acceso, desde cuándo, hasta cuándo,
 * quién lo otorgó?". Ver docs/DECISIONS.md.
 *
 * Único consumidor real en este hito: App\Training\Support\TrainingAccessGate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('granted_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_accesses');
    }
};
