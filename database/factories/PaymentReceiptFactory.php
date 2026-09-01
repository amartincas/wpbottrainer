<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentReceipt>
 */
class PaymentReceiptFactory extends Factory
{
    protected $model = PaymentReceipt::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'file_path' => 'receipts/1/'.Str::uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'file_hash' => hash('sha256', Str::random(20)),
            'source_type' => 'image',
            'extracted_data' => [
                'amount' => 50000, 'date' => now()->format('Y-m-d'), 'time' => null,
                'reference' => (string) fake()->numerify('#########'), 'entity' => 'Nequi',
                'payer_name' => null, 'uncertain' => false,
            ],
        ];
    }
}
