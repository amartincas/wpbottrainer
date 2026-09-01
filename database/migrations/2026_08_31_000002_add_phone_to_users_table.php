<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 7.1 (AlertService): el destinatario de una alerta administrativa por
 * WhatsApp se resuelve desde `User.is_super_admin` (ya global, no atado a un
 * Tenant — ver docs/DECISIONS.md), pero `users` no tenía ningún campo de
 * teléfono. Modificación mínima: un campo simple, sin validación especial
 * (mismo criterio que Contact.customer_phone/Tenant.wa_phone_number_id, ya
 * strings libres en este proyecto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
