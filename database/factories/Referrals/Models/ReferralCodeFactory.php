<?php

namespace Database\Factories\Referrals\Models;

use App\Models\Contact;
use App\Referrals\Models\ReferralCode;
use App\Referrals\Support\ReferralCodeGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralCode>
 *
 * Ruta/namespace exigidos por la convención de resolución de factories de
 * Laravel para un modelo fuera de `App\Models` (`App\Referrals\Models\
 * ReferralCode` -> `Database\Factories\Referrals\Models\ReferralCodeFactory`).
 */
class ReferralCodeFactory extends Factory
{
    protected $model = ReferralCode::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'code' => app(ReferralCodeGenerator::class)->generateUnique(),
        ];
    }
}
