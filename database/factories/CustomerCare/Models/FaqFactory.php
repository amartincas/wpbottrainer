<?php

namespace Database\Factories\CustomerCare\Models;

use App\CustomerCare\Models\Faq;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 *
 * Ruta/namespace exigidos por la convención de resolución de factories de
 * Laravel para un modelo fuera de `App\Models` (`App\CustomerCare\Models\
 * Faq` -> `Database\Factories\CustomerCare\Models\FaqFactory`).
 */
class FaqFactory extends Factory
{
    protected $model = Faq::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'question' => '¿Cuál es el horario de atención?',
            'answer' => 'Atendemos de lunes a viernes de 8am a 6pm.',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
