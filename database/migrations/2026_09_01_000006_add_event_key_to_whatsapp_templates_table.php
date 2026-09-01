<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajuste de Hito 8 (CustomerNotifier): permite que un operador etiquete una
 * plantilla ya registrada con el evento de sistema al que corresponde (p.ej.
 * "payment_confirmed"), sin que ningún dominio (Payments, Safety, futuros)
 * necesite conocer el nombre técnico real aprobado por Meta para ese tenant.
 *
 * Nullable y libre a propósito — mismo criterio que `Alert::category` en
 * App\Core\Alerts: Core/App\Core\Notifications no debe mantener un enum
 * cerrado de eventos, eso obligaría a tocar infraestructura cada vez que un
 * dominio nuevo necesite notificar. La lista de valores válidos hoy
 * (payment_confirmed, payment_rejected) vive únicamente en el Select del
 * formulario Filament (WhatsAppTemplateForm), como guía para el operador —
 * no como restricción de base de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->string('event_key')->nullable()->after('name');

            // Evita ambigüedad: dos plantillas del mismo tenant no pueden
            // reclamar el mismo evento (CustomerNotifier tomaría la primera
            // que encuentre, en silencio). NULLs no cuentan para esta
            // restricción (comportamiento estándar SQL), así que templates
            // de uso manual sin event_key no se ven afectadas.
            $table->unique(['tenant_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'event_key']);
            $table->dropColumn('event_key');
        });
    }
};
