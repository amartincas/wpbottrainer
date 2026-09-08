<?php

namespace Database\Factories\Referrals\Models;

use App\Models\Contact;
use App\Referrals\Models\Referral;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referral>
 *
 * Ruta/namespace exigidos por la convención de resolución de factories de
 * Laravel para un modelo fuera de `App\Models`.
 *
 * Por defecto, referente y referido pertenecen al MISMO tenant (el caso
 * válido más común) — mismo patrón de closures dependientes que
 * `PaymentFactory`.
 */
class ReferralFactory extends Factory
{
    protected $model = Referral::class;

    public function definition(): array
    {
        return [
            'referrer_contact_id' => Contact::factory(),
            'referred_contact_id' => function (array $attributes) {
                $referrer = Contact::find($attributes['referrer_contact_id']);

                return Contact::factory()->create(['tenant_id' => $referrer->tenant_id])->id;
            },
            'code' => 'REF-'.strtoupper(fake()->bothify('??####')),
        ];
    }
}
