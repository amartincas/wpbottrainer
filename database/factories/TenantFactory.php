<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

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
            'wa_access_token' => Str::random(40),
            'wa_phone_number_id' => (string) fake()->unique()->numerify('##########'),
            'wa_business_account_id' => (string) fake()->numerify('##########'),
            'wa_verify_token' => Str::random(32),
        ];
    }
}
