<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hito 8.4 — objetivos específicos. `primary_focus`/`secondary_focus`:
 * arrays de App\Training\Enums\MuscleFocus, nunca texto libre. Mismo
 * criterio null/[] ya usado en `restrictions`/`available_equipment`:
 * null = "todavía no se preguntó" (bloqueante para el onboarding), [] =
 * "se preguntó, el usuario no tiene una zona específica que priorizar"
 * (respuesta válida y completa — nunca vuelve a bloquear).
 *
 * Ambos campos son actualizables en cualquier momento posterior (Hito 8.4,
 * punto 3 aprobado) — no hay ningún mecanismo que los "congele" tras el
 * onboarding; una mención nueva del usuario los sobrescribe igual que
 * cualquier otro campo de TrainingProfile (mismo patrón de
 * applyExtractedFields() ya usado para goal/experience_level/etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->json('primary_focus')->nullable()->after('training_location');
            $table->json('secondary_focus')->nullable()->after('primary_focus');
        });
    }

    public function down(): void
    {
        Schema::table('training_profiles', function (Blueprint $table) {
            $table->dropColumn(['primary_focus', 'secondary_focus']);
        });
    }
};
