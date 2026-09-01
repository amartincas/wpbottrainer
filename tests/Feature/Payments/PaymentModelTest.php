<?php

use App\Models\Contact;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Payments\Enums\PaymentMethodType;
use App\Payments\Enums\PaymentStatus;

it('belongs to a contact and can have many receipts', function () {
    $contact = Contact::factory()->create();
    $payment = Payment::factory()->create(['contact_id' => $contact->id]);
    PaymentReceipt::factory()->count(2)->create(['payment_id' => $payment->id]);

    expect($payment->contact->is($contact))->toBeTrue();
    expect($payment->receipts)->toHaveCount(2);
});

it('casts enums and json fields correctly', function () {
    $payment = Payment::factory()->create([
        'method' => PaymentMethodType::ManualTransfer,
        'status' => PaymentStatus::UnderReview,
        'extracted_data' => ['amount' => 50000],
        'validation_flags' => ['amount_mismatch'],
    ]);

    $fresh = $payment->fresh();
    expect($fresh->method)->toBe(PaymentMethodType::ManualTransfer);
    expect($fresh->status)->toBe(PaymentStatus::UnderReview);
    expect($fresh->extracted_data)->toBe(['amount' => 50000]);
    expect($fresh->validation_flags)->toBe(['amount_mismatch']);
});

it('reports isOpen()/isAwaitingReceipt() correctly for each status', function () {
    expect(Payment::factory()->make(['status' => PaymentStatus::Pending])->isOpen())->toBeTrue();
    expect(Payment::factory()->make(['status' => PaymentStatus::Pending])->isAwaitingReceipt())->toBeTrue();
    expect(Payment::factory()->make(['status' => PaymentStatus::UnderReview])->isOpen())->toBeTrue();
    expect(Payment::factory()->make(['status' => PaymentStatus::UnderReview])->isAwaitingReceipt())->toBeFalse();
    expect(Payment::factory()->make(['status' => PaymentStatus::Confirmed])->isOpen())->toBeFalse();
    expect(Payment::factory()->make(['status' => PaymentStatus::Rejected])->isOpen())->toBeFalse();
    expect(Payment::factory()->make(['status' => PaymentStatus::Expired])->isOpen())->toBeFalse();
});

it('tracks who reviewed it', function () {
    $reviewer = User::factory()->create();
    $payment = Payment::factory()->create(['reviewed_by' => $reviewer->id]);

    expect($payment->reviewedBy->is($reviewer))->toBeTrue();
});

it('a PaymentReceipt belongs to a payment', function () {
    $payment = Payment::factory()->create();
    $receipt = PaymentReceipt::factory()->create(['payment_id' => $payment->id]);

    expect($receipt->payment->is($payment))->toBeTrue();
});
