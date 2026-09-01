<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Payment;
use App\Payments\Enums\PaymentMethodType;
use App\Payments\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'amount' => 50000,
            'currency' => 'COP',
            'method' => PaymentMethodType::ManualTransfer,
            'method_label' => 'Nequi',
            'reference' => null,
            'status' => PaymentStatus::Pending,
            'extracted_data' => null,
            'validation_flags' => null,
            'receipt_submitted_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'expires_at' => now()->addHours(48),
        ];
    }

    public function underReview(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::UnderReview,
            'reference' => (string) fake()->numerify('#########'),
            'receipt_submitted_at' => now(),
            'extracted_data' => [
                'amount' => 50000, 'date' => now()->format('Y-m-d'), 'time' => null,
                'reference' => (string) fake()->numerify('#########'), 'entity' => 'Nequi',
                'payer_name' => null, 'uncertain' => false,
            ],
            'validation_flags' => [],
        ]);
    }

    public function confirmed(): static
    {
        return $this->underReview()->state(fn () => [
            'status' => PaymentStatus::Confirmed,
            'reviewed_at' => now(),
        ]);
    }
}
