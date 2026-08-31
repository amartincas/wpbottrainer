<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('personality_type', ['vendedor', 'soporte', 'asesor']);
            $table->text('system_prompt');
            $table->enum('ai_provider', ['openai', 'grok']);
            $table->string('ai_model');
            $table->string('wa_access_token');
            $table->string('wa_phone_number_id');
            $table->string('wa_verify_token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
