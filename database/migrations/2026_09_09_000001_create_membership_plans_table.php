<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 11 — `MembershipPlan`: catálogo de opciones de compra única
 * (duración + precio) por Tenant. Deliberadamente NO es `Subscription`: sin
 * facturación recurrente, sin renovación automática, sin invoices, sin
 * prorrateo — solo una lista de opciones que un `Payment` puede referenciar
 * al crearse. `duration_months` es entero abierto (no un enum de
 * 1/3/6/12) — el negocio puede configurar cualquier cantidad de meses;
 * 1/3/6/12 son solo los valores iniciales esperados, nunca un límite del
 * modelo. Ver docs/DECISIONS.md.
 *
 * `is_active` permite retirar una opción del menú de WhatsApp sin romper
 * la integridad referencial de los `Payment` que ya la compraron —
 * `Payment.membership_plan_id` sigue apuntando a la fila aunque esté
 * inactiva o incluso si se borra (`nullOnDelete`, ver la migración
 * siguiente); lo que realmente gobierna el comportamiento de un `Payment`
 * ya creado es su propio snapshot (`membership_months`/`amount`/
 * `currency`), nunca este catálogo en el momento de la confirmación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('label'); // libre, ej. "3 meses" — mismo criterio que Payment.method_label
            $table->unsignedSmallInteger('duration_months'); // entero abierto, nunca un enum cerrado
            $table->decimal('price', 12, 2);
            $table->string('currency');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
