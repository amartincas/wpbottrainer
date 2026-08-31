<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 7 (E2E real): la transcripción de audio (Whisper) es exclusivamente
 * de OpenAI, independientemente del proveedor de chat que el Tenant tenga
 * configurado en ai_provider/ai_api_key (openai/grok/gemini). Antes de este
 * cambio, Ingest reutilizaba ai_api_key para llamar a Whisper — lo cual
 * rompía la transcripción en cualquier Tenant cuyo proveedor de chat no
 * fuera OpenAI (confirmado en la prueba E2E real: "Incorrect API key
 * provided" al mandar una key de Grok a la API de OpenAI). Ver D022 en
 * docs/DECISIONS.md.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('openai_transcription_api_key')->nullable()->after('ai_api_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('openai_transcription_api_key');
        });
    }
};
