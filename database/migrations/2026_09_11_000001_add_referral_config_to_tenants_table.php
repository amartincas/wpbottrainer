<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 13 — configuración del programa de Referidos, tenant-scoped.
 * Mismo criterio que la configuración de Payments (monthly_price,
 * nequi_number, payment_instructions, ...): vive directamente en `Tenant`,
 * no en una tabla propia del dominio nuevo — es configuración de negocio
 * simple (2 valores escalares), no un catálogo (a diferencia de
 * MembershipPlan, que sí justificaba su propia tabla).
 *
 * `wa_display_phone_number` es genérico de WhatsApp (el número marcable
 * real, distinto de `wa_phone_number_id`, el ID opaco de la Graph API) —
 * no es específico de Referidos, pero no existía ningún campo así hasta
 * ahora; Referidos es el primer consumidor (necesario para construir el
 * link wa.me de invitación), por eso se agrega aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('wa_display_phone_number')->nullable()->after('wa_phone_number_id');
            $table->unsignedSmallInteger('referral_reward_days')->default(3)->after('wa_display_phone_number');
            $table->boolean('referral_program_enabled')->default(true)->after('referral_reward_days');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['wa_display_phone_number', 'referral_reward_days', 'referral_program_enabled']);
        });
    }
};
