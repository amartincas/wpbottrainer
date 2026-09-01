<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment (Hito 8): evidencia de una transacción puntual — NO es
 * Subscription (compromiso recurrente, diferido) ni TrainingAccess
 * (entitlement, la única frontera real). Sin `tenant_id` propio, a
 * propósito — se alcanza vía `Contact.tenant_id`, mismo precedente que
 * TrainingProfile/WorkoutSession/TrainingAccess (Hito 4). Ver
 * docs/DECISIONS.md.
 *
 * `extracted_data` es lo que dijo la IA (Extract) — nunca autoritativo.
 * `validation_flags` es lo que dijo el código determinista (Decide) —
 * esto es lo que el humano realmente debe mirar antes de decidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency');
            $table->string('method'); // ManualTransfer | Gateway (PaymentMethodType)
            $table->string('method_label'); // "Nequi", "Daviplata", "PSE"... libre
            $table->string('reference')->nullable();
            $table->string('status'); // PaymentStatus
            $table->json('extracted_data')->nullable();
            $table->json('validation_flags')->nullable();
            $table->timestamp('receipt_submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
