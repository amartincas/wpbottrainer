<?php

use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;

/**
 * Hito 11 (D2) — gap real encontrado en la revisión: `PaymentStatus::Expired`
 * existía en el enum pero nada lo asignaba nunca. `payments:expire-stale`
 * corre cada 5 minutos vía el contenedor `scheduler` ya desplegado en
 * Hito 10 — ver routes/console.php.
 */

it('expires a pending Payment past its expires_at', function () {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Pending, 'expires_at' => now()->subHour()]);

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired);
});

it('never touches a pending Payment still within its expires_at window', function () {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Pending, 'expires_at' => now()->addHours(10)]);

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('never expires a Payment already under_review, even past its original expires_at', function () {
    $payment = Payment::factory()->create(['status' => PaymentStatus::UnderReview, 'expires_at' => now()->subHour()]);

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::UnderReview);
});

it('never touches a Payment already confirmed or rejected', function () {
    $confirmed = Payment::factory()->create(['status' => PaymentStatus::Confirmed, 'expires_at' => now()->subDay()]);
    $rejected = Payment::factory()->create(['status' => PaymentStatus::Rejected, 'expires_at' => now()->subDay()]);

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($confirmed->fresh()->status)->toBe(PaymentStatus::Confirmed);
    expect($rejected->fresh()->status)->toBe(PaymentStatus::Rejected);
});

it('is registered on the scheduler with a 5-minute frequency', function () {
    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
    $event = collect($schedule->events())->first(fn ($e) => str_contains($e->command, 'payments:expire-stale'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('*/5 * * * *');
});
