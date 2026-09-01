<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 8 (Payments): toda configuración específica de país/moneda/método de
 * pago vive en el Tenant — nunca hardcodeada en Core ni en el dominio
 * Payments (ver docs/DECISIONS.md). Un Tenant puede no ofrecer un método
 * (campo nulo) sin que eso rompa nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('currency')->default('COP')->after('ai_api_key');
            $table->string('country')->default('CO')->after('currency');
            $table->decimal('monthly_price', 12, 2)->nullable()->after('country');
            $table->text('payment_instructions')->nullable()->after('monthly_price');
            $table->string('nequi_number')->nullable()->after('payment_instructions');
            $table->string('daviplata_number')->nullable()->after('nequi_number');
            $table->string('gateway_provider')->nullable()->after('daviplata_number');
            $table->text('gateway_config')->nullable()->after('gateway_provider');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'currency', 'country', 'monthly_price', 'payment_instructions',
                'nequi_number', 'daviplata_number', 'gateway_provider', 'gateway_config',
            ]);
        });
    }
};
