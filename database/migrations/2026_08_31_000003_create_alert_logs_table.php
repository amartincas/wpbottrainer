<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro durable de alertas operativas (Hito 7.1, AlertService) — no es un
 * log general de la aplicación, solo lo que pasó por App\Core\Alerts\AlertService.
 * Sobrevive a los rebuilds de contenedor (a diferencia de storage/logs),
 * resolviendo también la deuda de "logs efímeros" documentada en el cierre
 * del Hito 7. `context` nunca debe contener secretos — ver
 * App\Core\Alerts\Alert, que sanea el contexto antes de que cualquier canal
 * (incluido este) lo vea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_logs', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('severity');
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('delivery_status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_logs');
    }
};
