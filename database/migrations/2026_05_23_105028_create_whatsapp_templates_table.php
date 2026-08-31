<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the whatsapp_templates table for storing Meta-approved
     * HSM (Highly Structured Message) templates per store.
     *
     * Supports two use cases:
     *   - Informative/Utility: shipping updates, order confirmations, etc.
     *   - Re-engagement/Marketing: reopen the 24h WhatsApp conversation window.
     */
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();

            // Multi-tenant: every template belongs to exactly one tenant
            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnDelete();

            // Technical name registered in Meta Business Manager
            // e.g. "shipping_update_v2" — must match exactly (case-sensitive)
            $table->string('name');

            // Human-readable preview for the Filament UI / operator dashboard
            // e.g. "Hola {{1}}, tu pedido {{2}} ha sido enviado."
            $table->text('body_preview');

            // Maps positional placeholder numbers to lead/product field names.
            // Allows auto-prefill from existing data before the operator sends.
            // Example: {"1": "customer_name", "2": "tracking_number", "3": "product_name"}
            $table->json('parameters_map')->nullable();

            // BCP-47 language + locale code as required by Meta Cloud API
            $table->string('language', 10)->default('es_CO');

            // Meta template category:
            //   'utility'   → transactional / informative (lower cost, no marketing opt-in needed)
            //   'marketing' → promotional / re-engagement (requires user opt-in)
            $table->enum('type', ['utility', 'marketing'])->default('utility');

            // Flags greeting/re-engagement templates that reopen the 24h window.
            // When true, a successful send triggers lead status reset so the
            // AIOrchestrator resumes processing on the customer's next reply.
            $table->boolean('is_reengagement')->default(false);

            $table->timestamps();

            // A tenant cannot register two templates with the same Meta name.
            // Meta itself enforces uniqueness per WABA, but we mirror it here
            // for data integrity and to simplify lookup queries.
            $table->unique(['tenant_id', 'name']);

            // Index for frequent query: fetch all templates for a given tenant
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
