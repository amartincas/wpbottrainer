<?php

namespace Database\Factories\CustomerCare\Models;

use App\CustomerCare\Models\CustomerServiceRequest;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerServiceRequest>
 *
 * Ruta/namespace exigidos por la convención de resolución de factories de
 * Laravel para un modelo fuera de `App\Models`.
 */
class CustomerServiceRequestFactory extends Factory
{
    protected $model = CustomerServiceRequest::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'message' => 'Necesito ayuda con mi cuenta.',
        ];
    }
}
