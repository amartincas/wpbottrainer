<?php

namespace Database\Factories\Referrals\Models;

use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use App\Referrals\Enums\ReferralRewardApplicationStatus;
use App\Referrals\Models\Referral;
use App\Referrals\Models\ReferralReward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralReward>
 *
 * Ruta/namespace exigidos por la convención de resolución de factories de
 * Laravel para un modelo fuera de `App\Models`.
 *
 * Por defecto, el Payment asociado pertenece al MISMO contact referido de
 * la Referral resuelta, y ya está `confirmed` (el caso real: una reward
 * solo existe si hubo una primera compra confirmada) — mismo patrón de
 * closures dependientes que `PaymentFactory`/`ReferralFactory`.
 */
class ReferralRewardFactory extends Factory
{
    protected $model = ReferralReward::class;

    public function definition(): array
    {
        return [
            'referral_id' => Referral::factory(),
            'payment_id' => function (array $attributes) {
                $referral = Referral::find($attributes['referral_id']);

                return Payment::factory()->create([
                    'contact_id' => $referral->referred_contact_id,
                    'status' => PaymentStatus::Confirmed,
                    'reviewed_at' => now(),
                ])->id;
            },
            'reward_days' => 3,
            'application_status' => ReferralRewardApplicationStatus::Applied,
        ];
    }

    public function notApplicable(): static
    {
        return $this->state(fn () => ['application_status' => ReferralRewardApplicationStatus::NotApplicable]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['application_status' => ReferralRewardApplicationStatus::Pending]);
    }
}
