<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PaymentReceipt (Hito 8): un Payment puede recibir más de un intento de
 * comprobante (foto borrosa, luego una más clara) — separada de Payment
 * para conservar cada intento, mismo motivo de granularidad que separó
 * ExerciseLog/ExerciseSet en el Hito 4. `file_hash` es la base del control
 * de duplicados (ver docs/DECISIONS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('file_path')->nullable(); // null cuando source_type = 'text' (sin archivo)
            $table->string('mime_type')->nullable();
            $table->string('file_hash', 64)->nullable(); // sha256 del archivo — null si no hay archivo
            $table->string('source_type'); // 'image' | 'text'
            $table->json('extracted_data')->nullable();
            $table->timestamps();

            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
