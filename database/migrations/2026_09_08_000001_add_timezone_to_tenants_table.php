<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 10 (Reminders/Temporal) — `timezone` es una propiedad ESTRUCTURAL del
 * Tenant, no un dato opcional: WpbotTrainer debe poder operar en múltiples
 * países, y ningún cálculo de fecha/hora de un Reminder puede depender de un
 * valor ausente. `NOT NULL DEFAULT 'America/Bogota'` puebla automáticamente
 * los tenants existentes (valor inicial de producto, nunca un fallback
 * silencioso en tiempo de ejecución — ver App\Training\Support\TimezoneResolver,
 * que jamás usa este default como lógica propia, solo lee lo que ya está
 * garantizado presente aquí). Identificador IANA (validado en
 * TenantForm/el modelo, nunca un offset fijo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // string() es NOT NULL por defecto (no se encadena ->nullable()):
            // el DEFAULT puebla automáticamente las filas existentes al
            // agregar la columna, sin backfill manual aparte.
            $table->string('timezone')->default('America/Bogota')->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
