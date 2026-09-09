<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 14 — FAQ configurable por Tenant. Contenido propio del Tenant SIN
 * ningún Contact intermedio (a diferencia de Payment/TrainingAccess) —
 * mismo precedente que MembershipPlan (Hito 11): un catálogo simple
 * tenant-scoped, con su propio `tenant_id`, no alcanzado vía un Contact.
 *
 * `is_active`/`sort_order`: mismo criterio administrativo que
 * MembershipPlan — activar/desactivar sin perder el registro, ordenar
 * manualmente. Deliberadamente sin categorías/tags/versionado — fuera de
 * alcance de este hito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('question');
            $table->text('answer');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
