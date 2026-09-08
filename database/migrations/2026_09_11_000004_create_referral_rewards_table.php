<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 13 — historial append-only de recompensas efectivamente generadas
 * (nunca se edita una fila existente, `$timestamps = false` en el modelo,
 * solo `created_at` con `useCurrent()` — mismo patrón que
 * TrainingAccessAudit).
 *
 * `referral_id` UNIQUE: como máximo una recompensa por Referral en toda su
 * vida (la regla "cada referido genera como máximo una recompensa").
 * `payment_id` UNIQUE: eje ortogonal de idempotencia — el mismo Payment
 * (evento duplicado, listener reintentado) nunca genera una segunda fila.
 * Ninguna de las dos por sí sola basta — juntas cubren "mismo Payment
 * reintentado" y "mismo Referral, Payment distinto" (no debería poder
 * pasar dado que solo la primera compra confirmada dispara esto, pero la
 * restricción de BD no depende de que esa lógica de aplicación sea
 * perfecta).
 *
 * `reward_days`: SNAPSHOT del valor de `Tenant.referral_reward_days` en el
 * momento exacto de la recompensa — nunca se relee después, ni siquiera si
 * el Tenant cambia su configuración (mismo criterio que
 * Payment.membership_months/amount frente a MembershipPlan).
 *
 * `application_status`: distingue explícitamente "la recompensa se generó"
 * de "el acceso del referente se modificó materialmente" — ver
 * App\Referrals\Enums\ReferralRewardApplicationStatus y docs/DECISIONS.md.
 * `pending` es la representación estructural de una recompensa que existe
 * pero no pudo aplicarse (referente Revoked o sin TrainingAccess) —
 * consultable directamente (`where('application_status', 'pending')`),
 * nunca depende de AlertLog para saber que existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->unique()->constrained('referrals')->cascadeOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('payments')->cascadeOnDelete();
            $table->unsignedSmallInteger('reward_days');
            $table->string('application_status');
            $table->timestamp('created_at')->useCurrent();

            $table->index('application_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
    }
};
