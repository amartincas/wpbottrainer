<?php

use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Support\PaymentValidationService;

function extractedFixture(array $overrides = []): array
{
    return array_merge([
        'amount' => 50000.0,
        'date' => now()->format('Y-m-d'),
        'time' => '10:00',
        'reference' => '123456789',
        'entity' => 'Nequi',
        'payer_name' => null,
        'uncertain' => false,
    ], $overrides);
}

it('has the bcmath extension available (required by bccomp() for exact decimal money comparison)', function () {
    // Regression guard: bccomp() se usa para comparar montos monetarios sin
    // los errores de redondeo de una comparación de floats. La imagen Docker
    // de producción no instalaba "bcmath" hasta este fix (era un supuesto
    // documentado en el Dockerfile que dejó de ser cierto cuando el Hito 8
    // introdujo este servicio) — en local (Herd) la extensión ya viene
    // habilitada, así que el resto de los tests de este archivo no habría
    // detectado la ausencia de la extensión en el entorno de producción.
    expect(extension_loaded('bcmath'))->toBeTrue();
});

it('returns no flags for a clean, matching receipt', function () {
    $payment = Payment::factory()->create(['amount' => 50000]);
    $service = new PaymentValidationService;

    expect($service->validate($payment, extractedFixture()))->toBe([]);
});

it('flags amount_mismatch when the extracted amount differs from the expected price', function () {
    $payment = Payment::factory()->create(['amount' => 50000]);
    $service = new PaymentValidationService;

    expect($service->validate($payment, extractedFixture(['amount' => 30000.0])))->toContain('amount_mismatch');
});

it('flags amount_unreadable when the amount could not be extracted', function () {
    $payment = Payment::factory()->create(['amount' => 50000]);
    $service = new PaymentValidationService;

    expect($service->validate($payment, extractedFixture(['amount' => null])))->toContain('amount_unreadable');
});

it('flags reference_missing when no reference was extracted', function () {
    $payment = Payment::factory()->create();
    $service = new PaymentValidationService;

    expect($service->validate($payment, extractedFixture(['reference' => null])))->toContain('reference_missing');
});

it('flags reference_already_used when the reference belongs to another CONFIRMED payment', function () {
    Payment::factory()->create(['reference' => 'DUP123', 'status' => PaymentStatus::Confirmed]);
    $payment = Payment::factory()->create();
    $service = new PaymentValidationService;

    expect($service->validate($payment, extractedFixture(['reference' => 'DUP123'])))->toContain('reference_already_used');
});

it('does not flag reference_already_used against a non-confirmed payment with the same reference', function () {
    Payment::factory()->create(['reference' => 'DUP123', 'status' => PaymentStatus::Rejected]);
    $payment = Payment::factory()->create();
    $service = new PaymentValidationService;

    expect($service->validate($payment, extractedFixture(['reference' => 'DUP123'])))->not->toContain('reference_already_used');
});

it('flags stale_receipt when the extracted date is far from the submission date', function () {
    $payment = Payment::factory()->create(['receipt_submitted_at' => now()]);
    $service = new PaymentValidationService;

    expect($service->validate($payment, extractedFixture(['date' => now()->subDays(30)->format('Y-m-d')])))
        ->toContain('stale_receipt');
});

it('flags date_unreadable when no date was extracted, without crashing', function () {
    $payment = Payment::factory()->create();
    $service = new PaymentValidationService;

    expect($service->validate($payment, extractedFixture(['date' => null])))->toContain('date_unreadable');
});

it('flags date_unreadable (not stale_receipt) when the extracted date is not parseable', function () {
    $payment = Payment::factory()->create();
    $service = new PaymentValidationService;

    expect($service->validate($payment, extractedFixture(['date' => 'ayer por la tarde'])))->toContain('date_unreadable');
});

it('flags uncertain_extraction when the AI expressed doubt', function () {
    $payment = Payment::factory()->create(['amount' => 50000]);
    $service = new PaymentValidationService;

    expect($service->validate($payment, extractedFixture(['uncertain' => true])))->toContain('uncertain_extraction');
});

it('never confirms or rejects — it only returns flags for a human to read', function () {
    $payment = Payment::factory()->create(['amount' => 50000, 'status' => PaymentStatus::UnderReview]);
    $service = new PaymentValidationService;

    $service->validate($payment, extractedFixture(['amount' => 1.0]));

    expect($payment->fresh()->status)->toBe(PaymentStatus::UnderReview);
});
