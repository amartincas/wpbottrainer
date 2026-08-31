<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Change columns from string to text to support longer values
            $table->text('wa_verify_token')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Revert to string if needed
            $table->string('wa_verify_token')->change();
        });
    }
};
