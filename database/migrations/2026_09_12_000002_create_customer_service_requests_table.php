<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 14 — registro mínimo, append-only, del hecho de negocio "un cliente
 * pidió atención humana" (explícitamente, o porque ninguna FAQ pudo
 * responder su pregunta con confianza). Es la fuente de verdad de la
 * solicitud — AlertLog sigue siendo exclusivamente infraestructura de
 * notificación (ver docs/DECISIONS.md).
 *
 * Sin `tenant_id` propio — se alcanza vía `contact_id`, mismo precedente
 * que Payment/TrainingAccess/TrainingAccessAudit/Referral/ReferralReward.
 * Deliberadamente SIN `status`: es un hecho histórico puro, sin ningún
 * ciclo de vida que rastrear en este hito (no es un sistema de tickets) —
 * mismo criterio que TrainingAccessAudit/ReferralReward: `created_at`
 * único, sin `updated_at`, nunca se edita una fila ya creada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service_requests');
    }
};
