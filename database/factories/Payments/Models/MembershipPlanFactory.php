<?php

namespace Database\Factories\Payments\Models;

use App\Models\Tenant;
use App\Payments\Models\MembershipPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipPlan>
 *
 * Ruta/namespace exigidos por la convención de resolución de factories de
 * Laravel para un modelo fuera de `App\Models` (`App\Payments\Models\
 * MembershipPlan` -> `Database\Factories\Payments\Models\MembershipPlanFactory`).
 */
class MembershipPlanFactory extends Factory
{
    protected $model = MembershipPlan::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'label' => '1 mes',
            'duration_months' => 1,
            'price' => 50000,
            'currency' => 'COP',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function months(int $months, ?string $label = null): static
    {
        return $this->state(fn () => [
            'duration_months' => $months,
            'label' => $label ?? ($months === 1 ? '1 mes' : "{$months} meses"),
        ]);
    }
}
