<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Change wa_access_token from string to text to support longer tokens
            $table->text('wa_access_token')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Revert to string if needed
            $table->string('wa_access_token')->change();
        });
    }
};
