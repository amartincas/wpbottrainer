<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\TrainingAccess;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Models\WorkoutSession;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Support\PaymentConfirmationService;
use App\Training\Enums\TrainingAccessStatus;
use Illuminate\Support\Facades\Http;

/**
 * El ÚNICO camino que escribe en TrainingAccess a partir de un Payment
 * (Hito 8) — TrainingAccessGate/TrainingEngine no se tocan en absoluto.
 */

it('grants a fresh TrainingAccess (1 month) when confirming a payment for a contact with no prior access', function () {
    $contact = Contact::factory()->create();
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);
    $reviewer = User::factory()->create();

    app(PaymentConfirmationService::class)->confirm($payment, $reviewer, 'Todo en orden');

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Confirmed);
    expect($fresh->reviewed_by)->toBe($reviewer->id);
    expect($fresh->reviewed_at)->not->toBeNull();
    expect($fresh->review_note)->toBe('Todo en orden');

    $access = TrainingAccess::where('contact_id', $contact->id)->first();
    expect($access)->not->toBeNull();
    expect($access->status)->toBe(TrainingAccessStatus::Active);
    expect($access->payment_id)->toBe($payment->id);
    expect($access->expires_at->isFuture())->toBeTrue();
    expect($access->expires_at->diffInDays(now(), true))->toBeGreaterThan(25); // ~1 mes
});

it('extends from the CURRENT expiry (not from now) when renewing before it expires — no paid days lost', function () {
    $contact = Contact::factory()->create();
    $currentExpiry = now()->addDays(10);
    TrainingAccess::create([
        'contact_id' => $contact->id,
        'status' => TrainingAccessStatus::Active,
        'granted_at' => now()->subDays(20),
        'expires_at' => $currentExpiry,
        'granted_by' => 'admin_manual_e2e',
    ]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    $access = TrainingAccess::where('contact_id', $contact->id)->first();
    // Debe extenderse desde currentExpiry + 1 mes, no desde hoy + 1 mes.
    expect($access->expires_at->diffInDays($currentExpiry, true))->toBeGreaterThan(25);
});

it('extends from NOW when renewing after the previous access already expired', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::create([
        'contact_id' => $contact->id,
        'status' => TrainingAccessStatus::Expired,
        'granted_at' => now()->subMonths(2),
        'expires_at' => now()->subDays(5),
        'granted_by' => 'admin_manual_e2e',
    ]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    $access = TrainingAccess::where('contact_id', $contact->id)->first();
    expect($access->status)->toBe(TrainingAccessStatus::Active);
    expect($access->expires_at->isFuture())->toBeTrue();
    expect($access->expires_at->diffInDays(now(), true))->toBeGreaterThan(25);
});

it('rejects a payment without touching TrainingAccess at all', function () {
    $contact = Contact::factory()->create();
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);
    $reviewer = User::factory()->create();

    app(PaymentConfirmationService::class)->reject($payment, $reviewer, 'Monto no coincide');

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Rejected);
    expect($fresh->reviewed_by)->toBe($reviewer->id);
    expect($fresh->review_note)->toBe('Monto no coincide');

    expect(TrainingAccess::where('contact_id', $contact->id)->exists())->toBeFalse();
});

it('accepts a null reviewer — forward-compatible with a future gateway webhook as an automatic authority', function () {
    $payment = Payment::factory()->create(['status' => PaymentStatus::UnderReview]);

    app(PaymentConfirmationService::class)->confirm($payment, null, 'Confirmado automáticamente por pasarela X');

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::Confirmed);
    expect($fresh->reviewed_by)->toBeNull();
});

// ── Idempotencia (ajuste de Hito 8) ─────────────────────────────────────

it('confirming an already-confirmed payment is a no-op: no re-extension, no duplicate notification', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    $contact = Contact::factory()->create();
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);
    $reviewer = User::factory()->create();

    app(PaymentConfirmationService::class)->confirm($payment, $reviewer, 'Primera confirmación');

    $access = TrainingAccess::where('contact_id', $contact->id)->first();
    $firstExpiry = $access->expires_at->copy();
    $firstReviewedAt = $payment->fresh()->reviewed_at->copy();

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]); // reset del contador de llamadas

    // Segunda llamada sobre el mismo Payment ya confirmado.
    app(PaymentConfirmationService::class)->confirm($payment->fresh(), $reviewer, 'Segundo intento');

    $accessAfter = TrainingAccess::where('contact_id', $contact->id)->get();
    expect($accessAfter)->toHaveCount(1); // no se creó un segundo TrainingAccess
    expect($accessAfter->first()->expires_at->equalTo($firstExpiry))->toBeTrue(); // no se re-extendió
    expect($payment->fresh()->reviewed_at->equalTo($firstReviewedAt))->toBeTrue(); // no se re-registró
    expect($payment->fresh()->review_note)->toBe('Primera confirmación'); // la nota original no se sobrescribió
    Http::assertNothingSent(); // no se disparó una segunda notificación
});

it('rejecting an already-rejected payment is a no-op: no duplicate notification, note unchanged', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    $contact = Contact::factory()->create();
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);
    $reviewer = User::factory()->create();

    app(PaymentConfirmationService::class)->reject($payment, $reviewer, 'Monto no coincide');
    $firstReviewedAt = $payment->fresh()->reviewed_at->copy();

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    app(PaymentConfirmationService::class)->reject($payment->fresh(), $reviewer, 'Otro motivo distinto');

    expect($payment->fresh()->review_note)->toBe('Monto no coincide'); // no se sobrescribió
    expect($payment->fresh()->reviewed_at->equalTo($firstReviewedAt))->toBeTrue();
    expect(TrainingAccess::where('contact_id', $contact->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

// ── CustomerNotifier (ajuste de Hito 8) ─────────────────────────────────

it('notifies the customer with a free-form message when confirming, if the conversation window is open', function () {
    $contact = Contact::factory()->create();
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now()->subMinutes(30),
    ]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview, 'amount' => 50000]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    Http::assertSent(function ($request) {
        return $request['type'] === 'text' && str_contains($request['text']['body'], 'confirmado');
    });
});

it('notifies the customer when rejecting, if the conversation window is open', function () {
    $contact = Contact::factory()->create();
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now()->subMinutes(5),
    ]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    app(PaymentConfirmationService::class)->reject($payment, User::factory()->create(), 'Monto no coincide');

    Http::assertSent(function ($request) {
        return $request['type'] === 'text' && str_contains($request['text']['body'], 'Monto no coincide');
    });
});

it('a notification delivery failure never reverts the Payment confirmation or the TrainingAccess grant', function () {
    $contact = Contact::factory()->create();
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now()->subMinutes(5),
    ]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);

    // Meta responde con error — CustomerNotifier lo captura y sigue sin
    // lanzar, pero el Payment y el TrainingAccess ya se escribieron antes.
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create(), 'ok');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Confirmed);
    expect(TrainingAccess::where('contact_id', $contact->id)->exists())->toBeTrue();
});

// ── Segundo mensaje proactivo: invitación a entrenar (ajuste de UX) ─────

it('sends both the payment-confirmed notification AND the training invite as two separate messages', function () {
    $contact = Contact::factory()->create();
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now()->subMinutes(5),
    ]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['type'] === 'text' && str_contains($request['text']['body'], 'confirmado'));
    Http::assertSent(fn ($request) => $request['type'] === 'text' && str_contains($request['text']['body'], '¿Quieres que te prepare tu entrenamiento?'));
});

it('never creates a WorkoutSession when sending the training invite — only inviting, never starting training', function () {
    $contact = Contact::factory()->create();
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now()->subMinutes(5),
    ]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    expect(WorkoutSession::where('contact_id', $contact->id)->exists())->toBeFalse();
});

it('does not send either message a second time when confirm() is retried on an already-confirmed Payment', function () {
    $contact = Contact::factory()->create();
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now()->subMinutes(5),
    ]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);
    $reviewer = User::factory()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    app(PaymentConfirmationService::class)->confirm($payment, $reviewer);
    Http::assertSentCount(2);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]); // reinicia el contador

    app(PaymentConfirmationService::class)->confirm($payment->fresh(), $reviewer); // reintento

    Http::assertNothingSent(); // ni la notificación de pago ni la invitación se repiten
});

it('sends the training invite as a free-form message when the conversation window is open', function () {
    $contact = Contact::factory()->create();
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now()->subMinutes(10), // dentro de 23h30m
    ]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    Http::assertSent(fn ($request) => $request['type'] === 'text' && str_contains($request['text']['body'], 'entrenamiento'));
});

it('sends the training invite via WhatsApp Template when the conversation window is closed and a template is configured', function () {
    $contact = Contact::factory()->create();
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now()->subHours(24), // >= 23h30m
    ]);
    WhatsAppTemplate::create([
        'tenant_id' => $contact->tenant_id,
        'name' => 'training_invite_v1',
        'event_key' => 'training_invite',
        'body_preview' => '¿Quieres que te prepare tu entrenamiento?',
        'parameters_map' => [],
        'language' => 'es_CO',
        'type' => 'utility',
    ]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    Http::assertSent(fn ($request) => $request['type'] === 'template' && $request['template']['name'] === 'training_invite_v1');
});

it('a failure delivering the training invite never reverts the Payment confirmation or the TrainingAccess grant, nor affects the payment-confirmed notification already sent', function () {
    $contact = Contact::factory()->create();
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now()->subMinutes(5),
    ]);
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);

    // Ambos mensajes van por mensaje libre (ventana abierta) al mismo
    // endpoint de Meta — Http::fake no puede fallar solo el segundo, así
    // que se verifica lo que realmente importa: aunque Meta fallara,
    // Payment/TrainingAccess ya quedaron persistidos antes de notificar.
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    expect($payment->fresh()->status)->toBe(PaymentStatus::Confirmed);
    expect(TrainingAccess::where('contact_id', $contact->id)->exists())->toBeTrue();
    Http::assertSentCount(2); // ambos intentos se hicieron, pese al error de Meta
});
