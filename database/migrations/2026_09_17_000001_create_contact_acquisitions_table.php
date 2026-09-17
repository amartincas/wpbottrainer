<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-B — atribución de adquisición: cómo llegó un Contact la PRIMERA vez
 * (meta_ads / referral / organic). `contact_id` es UNIQUE — misma garantía
 * exacta que `referrals.referred_contact_id` (Hito 13): una atribución por
 * Contact, en toda su vida, nunca se reemplaza (first-touch inmutable).
 *
 * Sin `tenant_id` propio — se alcanza vía `contact_id` -> `Contact.tenant_id`
 * (mismo precedente que `Referral`/`Payment`/`TrainingAccess`).
 *
 * Los campos `meta_*` son un snapshot histórico de lo que Meta adjuntó en el
 * webhook en el momento de la atribución — nunca se releen de Meta después.
 * `raw_referral_payload` conserva el objeto `referral` completo, verbatim,
 * como red de seguridad ante campos que Meta pueda enviar y que hoy no
 * anticipamos en columnas propias (ver auditoría P1-B).
 *
 * `referral_id` es nullable y apunta a `App\Referrals\Models\Referral`
 * únicamente cuando `source = referral` — el sistema de Referidos (Hito 13)
 * permanece completamente intacto; esta tabla solo LEE su resultado, nunca
 * lo condiciona ni lo modifica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_acquisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained('contacts')->cascadeOnDelete();
            $table->string('source');
            $table->string('meta_ad_id')->nullable();
            $table->string('meta_ctwa_clid')->nullable();
            $table->string('meta_source_type')->nullable();
            $table->text('meta_headline')->nullable();
            $table->text('meta_body')->nullable();
            $table->string('meta_media_url')->nullable();
            $table->json('raw_referral_payload')->nullable();
            $table->foreignId('referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_acquisitions');
    }
};
