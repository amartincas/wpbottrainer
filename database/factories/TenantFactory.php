<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Payments\Models\MembershipPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * Hito 11 — todo Tenant de test creado con `monthly_price` (el default
     * de esta factory) recibe automáticamente un `MembershipPlan` de 1 mes
     * equivalente — mismo criterio que la migración de compatibilidad para
     * Tenants reales. Sin esto, CADA test existente que ya asumía un
     * precio único por Tenant (la enorme mayoría de los tests de Payments,
     * de antes de este hito) tendría que crear su propio MembershipPlan a
     * mano. Un test que necesite el caso "sin membresía configurada" o
     * "varias membresías" debe borrar/agregar explícitamente después de
     * crear el Tenant — esto es solo el comportamiento por defecto.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant) {
            if ($tenant->monthly_price !== null) {
                MembershipPlan::create([
                    'tenant_id' => $tenant->id,
                    'label' => '1 mes',
                    'duration_months' => 1,
                    'price' => $tenant->monthly_price,
                    'currency' => $tenant->currency,
                    'is_active' => true,
                ]);
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'personality_type' => fake()->randomElement(['vendedor', 'soporte', 'asesor']),
            'system_prompt' => 'You are a helpful assistant.',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o-mini',
            'ai_api_key' => Str::random(40),
            // Independiente de ai_api_key a propósito: Whisper siempre usa
            // OpenAI, sin importar qué proveedor de chat tenga el Tenant.
            'openai_transcription_api_key' => Str::random(40),
            'wa_access_token' => Str::random(40),
            'wa_phone_number_id' => (string) fake()->unique()->numerify('##########'),
            'wa_business_account_id' => (string) fake()->numerify('##########'),
            'wa_verify_token' => Str::random(32),
            'currency' => 'COP',
            'country' => 'CO',
            'timezone' => 'America/Bogota',
            'monthly_price' => 50000,
            'payment_instructions' => 'Incluye tu número de WhatsApp como referencia.',
            'nequi_number' => (string) fake()->numerify('3##-###-####'),
            'daviplata_number' => null,
            'gateway_provider' => null,
            'gateway_config' => null,
        ];
    }
}
