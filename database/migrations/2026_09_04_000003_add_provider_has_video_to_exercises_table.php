<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 9.3 (sincronización completa) — señal de disponibilidad de video
 * del PROVEEDOR, separada por completo de `is_active` (aprobación de
 * WpbotTrainer) y de `video_url` (siempre null para un ejercicio de
 * proveedor, ver Exercise::booted()). Nullable: `null` = no aplica (un
 * ejercicio manual, `provider=null`, nunca tuvo este concepto); `true`/
 * `false` = lo que el proveedor reportó en la última sincronización.
 *
 * Se refresca en cada re-sync como cualquier otro campo de metadata del
 * proveedor (nunca curado por un humano) — un ejercicio puede pasar de
 * con-video a sin-video y viceversa sin que eso reactive ni desactive
 * nada por sí solo; `is_active` sigue siendo una decisión humana aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->boolean('provider_has_video')->nullable()->after('provider_metadata');
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn('provider_has_video');
        });
    }
};
