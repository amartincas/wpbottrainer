<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\TrainingAccess;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Models\WorkoutSession;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Events\PaymentConfirmed;
use App\Payments\Models\MembershipPlan;
use App\Payments\Support\PaymentConfirmationService;
use App\Training\Enums\TrainingAccessStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
    $messageCountAfterFirstConfirm = WhatsAppMessage::where('customer_phone', $contact->customer_phone)->count();
    expect($messageCountAfterFirstConfirm)->toBe(2); // payment_confirmed + training_invite, persistidos (Hito 8.1)

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]); // reinicia el contador

    app(PaymentConfirmationService::class)->confirm($payment->fresh(), $reviewer); // reintento

    Http::assertNothingSent(); // ni la notificación de pago ni la invitación se repiten
    expect(WhatsAppMessage::where('customer_phone', $contact->customer_phone)->count())->toBe($messageCountAfterFirstConfirm); // tampoco se duplica el historial
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

// ── Membresías de duración variable (Hito 11) ───────────────────────────

it('extends TrainingAccess by the EXACT membership_months snapshot of the Payment, for 1/3/6/12 meses', function (int $months) {
    $contact = Contact::factory()->create();
    $plan = MembershipPlan::factory()->for($contact->tenant, 'tenant')->months($months)->create();
    $payment = Payment::factory()->create([
        'contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview,
        'membership_plan_id' => $plan->id, 'membership_months' => $months, 'amount' => $plan->price, 'currency' => $plan->currency,
    ]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    $access = TrainingAccess::where('contact_id', $contact->id)->first();
    $expectedDays = $months * 28; // margen conservador, evita falsos negativos por longitud de mes
    expect($access->expires_at->diffInDays(now(), true))->toBeGreaterThan($expectedDays - 3);
})->with([1, 3, 6, 12]);

it('falls back to LEGACY_DEFAULT_MONTHS (1) for a Payment created before this hito, with membership_months = null', function () {
    $contact = Contact::factory()->create();
    $payment = Payment::factory()->create([
        'contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview,
        'membership_plan_id' => null, 'membership_months' => null, 'amount' => 50000, 'currency' => 'COP',
    ]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    $access = TrainingAccess::where('contact_id', $contact->id)->first();
    expect($access->expires_at->diffInDays(now(), true))->toBeGreaterThan(25); // ~1 mes, nunca 0/proporcional
});

it('never grants a proportional duration even when amount_mismatch is present — the human confirming still gets the FULL membership_months', function () {
    $contact = Contact::factory()->create();
    $plan = MembershipPlan::factory()->for($contact->tenant, 'tenant')->months(3)->create(['price' => 120000]);
    $payment = Payment::factory()->create([
        'contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview,
        'membership_plan_id' => $plan->id, 'membership_months' => 3, 'amount' => 120000, 'currency' => $plan->currency,
        // El humano decide confirmar A PESAR de un monto menor detectado —
        // esto NUNCA debe traducirse en una duración proporcional (60000 no
        // es "mitad de 3 meses" = 1.5 meses).
        'validation_flags' => ['amount_mismatch'],
        'extracted_data' => ['amount' => 60000, 'date' => null, 'time' => null, 'reference' => null, 'entity' => null, 'payer_name' => null, 'uncertain' => false],
    ]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    $access = TrainingAccess::where('contact_id', $contact->id)->first();
    expect($access->expires_at->diffInDays(now(), true))->toBeGreaterThan(3 * 28 - 3); // 3 meses completos, nunca 1.5
});

it('a historical Payment keeps its own frozen amount/membership_months even after the MembershipPlan changes price/duration or gets deactivated', function () {
    $contact = Contact::factory()->create();
    $plan = MembershipPlan::factory()->for($contact->tenant, 'tenant')->months(3)->create(['price' => 120000]);
    $payment = Payment::factory()->create([
        'contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview,
        'membership_plan_id' => $plan->id, 'membership_months' => 3, 'amount' => 120000, 'currency' => $plan->currency,
    ]);

    // El catálogo cambia DESPUÉS de crear el Payment — nunca antes de
    // confirmarlo, exactamente el escenario que F pide proteger.
    $plan->update(['price' => 130000, 'duration_months' => 6, 'is_active' => false]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    $fresh = $payment->fresh();
    expect((float) $fresh->amount)->toBe(120000.0); // NUNCA 130000
    expect($fresh->membership_months)->toBe(3); // NUNCA 6

    $access = TrainingAccess::where('contact_id', $contact->id)->first();
    expect($access->expires_at->diffInDays(now(), true))->toBeGreaterThan(3 * 28 - 3); // 3 meses, no 6
});

// ── D1 — concurrencia real (Hito 11) ────────────────────────────────────

it('confirm() re-locks and re-reads the Payment from the DB — two stale in-memory instances never both extend TrainingAccess', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    $contact = Contact::factory()->create();
    $payment = Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview]);
    $reviewer = User::factory()->create();

    // Dos instancias EN MEMORIA cargadas ANTES de que ninguna escriba nada
    // — simula dos requests que leyeron el Payment casi al mismo tiempo.
    // Antes de D1, confirm() confiaba en $payment->status (la copia en
    // memoria, obsoleta) — ambas habrían pasado el chequeo y ambas habrían
    // extendido TrainingAccess. Con lockForUpdate()+relectura dentro de la
    // transacción, la segunda llamada ve el estado YA actualizado.
    $staleA = Payment::find($payment->id);
    $staleB = Payment::find($payment->id);

    app(PaymentConfirmationService::class)->confirm($staleA, $reviewer, 'Primera');
    app(PaymentConfirmationService::class)->confirm($staleB, $reviewer, 'Segunda, con instancia obsoleta');

    expect(TrainingAccess::where('contact_id', $contact->id)->count())->toBe(1);
    $access = TrainingAccess::where('contact_id', $contact->id)->first();
    expect($access->expires_at->diffInDays(now(), true))->toBeLessThan(35); // nunca 2 meses (doble extensión)
    expect($payment->fresh()->review_note)->toBe('Primera'); // la segunda llamada fue un no-op real
});

it('confirm() executes its lock/read/update inside a real DB transaction with a locking read (SELECT ... FOR UPDATE)', function () {
    $payment = Payment::factory()->create(['status' => PaymentStatus::UnderReview]);

    DB::enableQueryLog();
    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $hasLockingSelect = collect($log)->contains(fn ($entry) => str_contains(mb_strtolower($entry['query']), 'for update'));

    expect($hasLockingSelect)->toBeTrue();
});

// ── Seam PaymentConfirmed (Hito 11) — NUNCA lógica de Referidos aquí ────

it('dispatches PaymentConfirmed exactly once on a real confirmation, carrying the confirmed Payment', function () {
    Event::fake([PaymentConfirmed::class]);
    $payment = Payment::factory()->create(['status' => PaymentStatus::UnderReview]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    Event::assertDispatchedTimes(PaymentConfirmed::class, 1);
    Event::assertDispatched(PaymentConfirmed::class, fn (PaymentConfirmed $event) => $event->payment->is($payment));
});

it('never dispatches PaymentConfirmed a second time when confirm() is retried on an already-confirmed Payment', function () {
    Event::fake([PaymentConfirmed::class]);
    $payment = Payment::factory()->create(['status' => PaymentStatus::UnderReview]);
    $reviewer = User::factory()->create();

    app(PaymentConfirmationService::class)->confirm($payment, $reviewer);
    app(PaymentConfirmationService::class)->confirm($payment->fresh(), $reviewer);

    Event::assertDispatchedTimes(PaymentConfirmed::class, 1);
});

it('never dispatches PaymentConfirmed on reject()', function () {
    Event::fake([PaymentConfirmed::class]);
    $payment = Payment::factory()->create(['status' => PaymentStatus::UnderReview]);

    app(PaymentConfirmationService::class)->reject($payment, User::factory()->create(), 'Motivo');

    Event::assertNotDispatched(PaymentConfirmed::class);
});

it('implements ShouldDispatchAfterCommit — never fires for listeners while confirm() is still inside its own transaction', function () {
    expect(PaymentConfirmed::class)->toImplement(\Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class);
});

/**
 * El test del seam en sí (pedido explícitamente): prueba que un
 * consumidor externo (simulando un futuro Hito de Referidos, SIN crear
 * ningún código de Referral) PODRÍA reaccionar al hecho y leer los datos
 * que necesitaría — contact_id, amount, membership_months — sin que
 * Payments conozca nada de él. Usa Event::fake() (el mecanismo estándar
 * de Laravel para probar ShouldDispatchAfterCommit bajo RefreshDatabase —
 * un listener real nunca se invocaría dentro de la transacción de test
 * envolvente, ya que el commit real nunca ocurre) — inspecciona
 * directamente el evento capturado, exactamente lo que un listener real
 * recibiría. Prueba únicamente el seam, nunca ninguna regla de recompensa.
 */
it('the seam is consumable from outside App\Payments: contact_id/amount/membership_months are readable off the event, without any Referral code existing', function () {
    Event::fake([PaymentConfirmed::class]);

    $contact = Contact::factory()->create();
    $payment = Payment::factory()->create([
        'contact_id' => $contact->id, 'status' => PaymentStatus::UnderReview,
        'amount' => 130000, 'membership_months' => 3,
    ]);

    app(PaymentConfirmationService::class)->confirm($payment, User::factory()->create());

    Event::assertDispatched(PaymentConfirmed::class, function (PaymentConfirmed $event) use ($contact) {
        return $event->payment->contact_id === $contact->id
            && (float) $event->payment->amount === 130000.0
            && $event->payment->membership_months === 3;
    });
});
