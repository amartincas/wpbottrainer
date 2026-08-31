<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Make technical fields nullable for lazy registration
            $table->string('personality_type')->nullable()->change();
            $table->longText('system_prompt')->nullable()->change();
            $table->string('ai_provider')->nullable()->change();
            $table->string('ai_model')->nullable()->change();
            $table->text('wa_access_token')->nullable()->change();
            $table->string('wa_phone_number_id')->nullable()->change();
            $table->string('wa_verify_token')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('personality_type')->nullable(false)->change();
            $table->longText('system_prompt')->nullable(false)->change();
            $table->string('ai_provider')->nullable(false)->change();
            $table->string('ai_model')->nullable(false)->change();
            $table->text('wa_access_token')->nullable(false)->change();
            $table->string('wa_phone_number_id')->nullable(false)->change();
            $table->string('wa_verify_token')->nullable(false)->change();
        });
    }
};
