<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 9.3 (post-deploy) — registro de auditoría, append-only, de cada
 * resolución de video EXITOSA de un ejercicio de proveedor. Nace de un
 * hallazgo real durante la investigación del uso de cuota de YMove:
 * `provider_has_video` (Hito 9.3, D038) es una señal CRUDA del proveedor
 * ("el catálogo dice que existe video") — nunca prueba que ALGUNA VEZ se
 * haya resuelto con éxito. Esta tabla es la única fuente de verdad para
 * eso: "video validado" en Filament se deriva de aquí, nunca de
 * `provider_has_video`.
 *
 * `exercise_id` en vez de `provider`+`provider_exercise_id`: la fila SÍ
 * pertenece a un `Exercise` interno concreto (nuestra tabla, no la del
 * proveedor) — `provider`/`provider_exercise_id` se denormalizan además,
 * de solo lectura, para que el registro siga siendo legible por sí solo
 * aunque el Exercise cambie de proveedor en el futuro (caso hoy
 * inexistente, pero la denormalización es gratis).
 *
 * NUNCA guarda la URL de video (firmada, con token) — mismo criterio que
 * el resto del sistema desde Hito 9: solo metadata sobre el acceso, nunca
 * el recurso en sí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_video_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_exercise_id');
            $table->string('variant')->default('default');
            $table->timestamp('resolved_at');
            $table->timestamps();

            $table->index(['exercise_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_video_accesses');
    }
};
