<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('current_product_id')
                  ->nullable()
                  ->after('customer_phone')
                  ->constrained('products')
                  ->nullOnDelete()
                  ->comment('Producto establecido en esta conversación — evita que turnos sin mención explícita (ej. "sí", "me parece bien") caigan al catálogo completo y mezclen productos no relacionados en el prompt');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_product_id');
        });
    }
};
