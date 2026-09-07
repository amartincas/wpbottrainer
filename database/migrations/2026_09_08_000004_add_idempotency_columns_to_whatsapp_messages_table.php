<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 10 — idempotencia de envíos proactivos de Reminder.
 *
 * `idempotency_key`: identidad lógica de UN intento de envío
 * ("reminder:{id}:occurrence:{fire_at_timestamp}") — índice único, nulo
 * para todo mensaje que no venga de un Reminder (conversación normal).
 *
 * `dispatch_confirmed_at`: se fija ÚNICAMENTE cuando la llamada real a Meta
 * responde con éxito. Es la única fuente DURABLE (no caché) de "esto sí
 * salió" — CustomerNotifier la usa para decidir si un WhatsAppMessage ya
 * existente con la misma idempotency_key representa una entrega confirmada
 * (no reintentar) o un intento anterior de resultado desconocido (sí
 * reintentar, reusando la misma fila, nunca creando una segunda).
 *
 * Garantía resultante: "como máximo un envío CONFIRMADO por Reminder +
 * ocurrencia" — nunca se describe como "exactly once" (ver D053/docs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('content');
            $table->timestamp('dispatch_confirmed_at')->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn(['idempotency_key', 'dispatch_confirmed_at']);
        });
    }
};
