<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 13 — atribución: qué Contact fue referido por cuál otro Contact, y
 * con qué código. `referred_contact_id` es UNIQUE — la garantía central del
 * dominio: una atribución efectiva por referido, en toda su vida, nunca se
 * reemplaza (ver docs/DECISIONS.md). `code` es un snapshot del código
 * usado en el momento (los códigos son estables en este hito, pero el
 * snapshot documenta exactamente qué se usó, mismo criterio que
 * Payment.membership_months).
 *
 * Deliberadamente SIN columna `status`: "atribuido" vs "recompensado" es
 * 100% derivable de si existe (o no) un ReferralReward asociado
 * (`Referral::isRewarded()`) — una columna redundante duplicaría ese hecho
 * sin aportar nada, y podría desincronizarse.
 *
 * Sin `tenant_id` propio — se alcanza vía `referred_contact_id`/
 * `referrer_contact_id` -> Contact.tenant_id (mismo precedente que
 * Payment/TrainingAccess). Ambos Contacts SIEMPRE pertenecen al mismo
 * tenant — se valida explícitamente antes de crear esta fila (nunca a
 * nivel de esquema, porque el esquema no puede expresar "misma columna en
 * dos filas distintas").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referred_contact_id')->unique()->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('referrer_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('code');
            $table->timestamps();

            $table->index('referrer_contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
