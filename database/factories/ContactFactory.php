<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_phone' => fake()->unique()->numerify('57300#######'),
            'customer_name' => fake()->name(),
            'delivery_address_or_location' => null,
            'product_service_name' => null,
            'preferred_date_time' => null,
            'summary' => fake()->sentence(),
            'is_processed' => false,
            'bot_active' => true,
        ];
    }
}
