<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DeclaredHealthCondition;
use App\Models\TrainingRestriction;
use App\Models\User;
use App\Training\Enums\BodyRegion;
use App\Training\Enums\HealthConditionStatus;
use App\Training\Enums\RestrictionSource;
use App\Training\Events\DeclaredHealthConditionResolved;
use App\Training\Listeners\SendHealthReviewResolutionNotification;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\DeclaredHealthConditionRecorder;
use App\Training\Support\FunctionalLimitationCanonicalMapper;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Hito O1 (Notificación proactiva de revisión de salud) — mismo patrón de
 * test EXACTO que
 * tests/Feature/Referrals/SendReferralIntroductionOnWorkoutCompletedTest.php:
 * el listener se invoca DIRECTAMENTE (`->handle($event)`) para probar su
 * comportamiento (nunca disparando el evento real bajo la transacción
 * envolvente de RefreshDatabase), y el CABLEADO real (dispatch desde el
 * Recorder + registro en AppServiceProvider) se prueba aparte, de forma
 * automatizada (Event::fake()/assertDispatched + reflection de listeners
 * registrados), nunca solo "por inspección".
 */
function healthNotifRecorder(): DeclaredHealthConditionRecorder
{
    return new DeclaredHealthConditionRecorder(new BodyRegionCanonicalMapper, new FunctionalLimitationCanonicalMapper);
}

function healthNotifListener(): SendHealthReviewResolutionNotification
{
    return app(SendHealthReviewResolutionNotification::class);
}

function openWaWindowForHealthNotif(Contact $contact): void
{
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now(),
    ]);
}

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
});

// ── A. resolveWithoutRestriction() -> notificación ─────────────────────────

it('A0: the listener defensively sends nothing for a condition that is not actually resolved (still PendingReview)', function () {
    $contact = Contact::factory()->create();
    openWaWindowForHealthNotif($contact);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]); // status stays PendingReview

    healthNotifListener()->handle(new DeclaredHealthConditionResolved($condition->fresh()));

    Http::assertNothingSent();
});

it('A: end-to-end — resolveWithoutRestriction() real resolution triggers exactly the expected outbound message', function () {
    $contact = Contact::factory()->create();
    openWaWindowForHealthNotif($contact);
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);

    healthNotifRecorder()->resolveWithoutRestriction($condition, $reviewer, 'No amerita restricción.');

    healthNotifListener()->handle(new DeclaredHealthConditionResolved($condition->fresh()));

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'ya puedes continuar')
        && str_contains(data_get($request->data(), 'text.body', ''), 'Buenas noticias'));
});

// ── B. resolveWithRestriction() -> notificación distinta ───────────────────

it('B: end-to-end — resolveWithRestriction() real resolution triggers the with-restriction message, never the no-restriction one', function () {
    $contact = Contact::factory()->create();
    openWaWindowForHealthNotif($contact);
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);

    $restriction = healthNotifRecorder()->resolveWithRestriction(
        $condition, BodyRegion::Knee, RestrictionSource::UserExplicit, $reviewer, 'Confirmado.',
    );

    expect($restriction)->not->toBeNull();
    expect(TrainingRestriction::count())->toBe(1);

    healthNotifListener()->handle(new DeclaredHealthConditionResolved($condition->fresh()));

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'ajustamos tu plan')
        && ! str_contains(data_get($request->data(), 'text.body', ''), 'Buenas noticias'));
});

// ── C. Doble resolución — no segunda restriction, no segunda notificación confirmada ──

it('C1: a second resolveWithRestriction() attempt on an already-resolved condition creates no second TrainingRestriction and never dispatches the event again', function () {
    $contact = Contact::factory()->create();
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);
    Event::fake([DeclaredHealthConditionResolved::class]);

    $first = healthNotifRecorder()->resolveWithRestriction($condition, BodyRegion::Knee, RestrictionSource::UserExplicit, $reviewer, 'Primero.');
    expect($first)->not->toBeNull();

    $second = healthNotifRecorder()->resolveWithRestriction($condition, BodyRegion::Shoulder, RestrictionSource::UserVague, $reviewer, 'Segundo intento.');

    expect($second)->toBeNull();
    expect(TrainingRestriction::count())->toBe(1); // nunca una segunda
    expect($condition->fresh()->status)->toBe(HealthConditionStatus::ResolvedRestrictionCreated);
    // El body_region de la PRIMERA resolución sobrevive — el segundo
    // intento nunca modificó nada.
    expect(TrainingRestriction::first()->body_region)->toBe(BodyRegion::Knee);

    Event::assertDispatchedTimes(DeclaredHealthConditionResolved::class, 1);
});

it('C2: a second resolveWithoutRestriction() attempt on an already-resolved condition never re-writes the state nor dispatches again', function () {
    $contact = Contact::factory()->create();
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);
    Event::fake([DeclaredHealthConditionResolved::class]);

    healthNotifRecorder()->resolveWithoutRestriction($condition, $reviewer, 'Primera nota.');
    $reviewedAtFirst = $condition->fresh()->reviewed_at;

    healthNotifRecorder()->resolveWithoutRestriction($condition, $reviewer, 'Segunda nota — nunca debería aplicarse.');

    $fresh = $condition->fresh();
    expect($fresh->status)->toBe(HealthConditionStatus::ResolvedNoRestriction);
    expect($fresh->review_note)->toBe('Primera nota.'); // nunca sobreescrito
    expect($fresh->reviewed_at->eq($reviewedAtFirst))->toBeTrue();

    Event::assertDispatchedTimes(DeclaredHealthConditionResolved::class, 1);
});

it('C3: the same condition never produces two CONFIRMED WhatsApp deliveries, even if the listener runs twice', function () {
    $contact = Contact::factory()->create();
    openWaWindowForHealthNotif($contact);
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);
    healthNotifRecorder()->resolveWithoutRestriction($condition, $reviewer, 'Nota.');
    $fresh = $condition->fresh();

    healthNotifListener()->handle(new DeclaredHealthConditionResolved($fresh));
    healthNotifListener()->handle(new DeclaredHealthConditionResolved($fresh)); // reprocesado

    Http::assertSentCount(1); // CustomerNotifier: idempotencyKey ya confirmada
});

// ── D. PendingReview -> sin notificación ────────────────────────────────────

it('D: a condition that is never resolved (still PendingReview) never triggers a real dispatch from the Recorder', function () {
    $contact = Contact::factory()->create();
    Event::fake([DeclaredHealthConditionResolved::class]);

    healthNotifRecorder()->declare($contact, 'tengo una molestia', \App\Training\Enums\HealthConditionCategory::PossibleInjury);

    Event::assertNotDispatched(DeclaredHealthConditionResolved::class);
});

// ── E. Rollback de transacción -> ni notificación ni evento procesable ─────

it('E: if resolveWithRestriction() fails mid-transaction, no event is dispatched and no notification is ever attempted', function () {
    $contact = Contact::factory()->create();
    openWaWindowForHealthNotif($contact);
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);
    Event::fake([DeclaredHealthConditionResolved::class]);

    DeclaredHealthCondition::saving(function (DeclaredHealthCondition $model) use ($condition) {
        if ((int) $model->id === $condition->id && $model->status === HealthConditionStatus::ResolvedRestrictionCreated) {
            throw new RuntimeException('simulated failure');
        }
    });

    try {
        expect(fn () => healthNotifRecorder()->resolveWithRestriction(
            $condition, BodyRegion::Knee, RestrictionSource::UserExplicit, $reviewer,
        ))->toThrow(RuntimeException::class);
    } finally {
        DeclaredHealthCondition::flushEventListeners();
    }

    expect(TrainingRestriction::count())->toBe(0);
    expect($condition->fresh()->status)->toBe(HealthConditionStatus::PendingReview);
    Event::assertNotDispatched(DeclaredHealthConditionResolved::class);
    Http::assertNothingSent();
});

// ── F. Fallo del notifier -> la resolución persistida NO se revierte ───────

it('F: a CustomerNotifier delivery failure never reverts the already-persisted resolution', function () {
    $contact = Contact::factory()->create();
    // Sin Conversation (ventana cerrada) y sin WhatsAppTemplate configurado
    // -> sendViaTemplate() no encuentra plantilla -> notify() falla
    // internamente (retorna unconfirmed), nunca lanza.
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);

    $restriction = healthNotifRecorder()->resolveWithRestriction($condition, BodyRegion::Knee, RestrictionSource::UserExplicit, $reviewer, 'Nota.');

    expect(fn () => healthNotifListener()->handle(new DeclaredHealthConditionResolved($condition->fresh())))->not->toThrow(Throwable::class);

    // La resolución sigue persistida pese a que la notificación no pudo entregarse.
    expect($restriction)->not->toBeNull();
    expect($condition->fresh()->status)->toBe(HealthConditionStatus::ResolvedRestrictionCreated);
    Http::assertNothingSent(); // nunca se intentó un envío real sin plantilla configurada
});

// ── G. Siguiente inbound: TrainingAccessGate no depende de la notificación ──

it('G: TrainingAccessGate allows continuing right after resolution, independent of whether the notification was ever sent', function () {
    $contact = Contact::factory()->create();
    \App\Models\TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);

    // Sin abrir ventana de WhatsApp ni plantilla -> la notificación nunca
    // se entrega confirmada, pero el gate no debe importarle.
    healthNotifRecorder()->resolveWithoutRestriction($condition, $reviewer, 'Nota.');

    $result = app(\App\Training\Support\TrainingAccessGate::class)->authorize($contact->fresh());

    expect($result->allowed)->toBeTrue();
});

// ── Cableado real: el evento se despacha desde el Recorder ─────────────────

it('resolveWithRestriction() really dispatches DeclaredHealthConditionResolved on a genuine resolution', function () {
    Event::fake([DeclaredHealthConditionResolved::class]);
    $contact = Contact::factory()->create();
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);

    healthNotifRecorder()->resolveWithRestriction($condition, BodyRegion::Knee, RestrictionSource::UserExplicit, $reviewer);

    Event::assertDispatched(DeclaredHealthConditionResolved::class, fn ($event) => $event->condition->id === $condition->id
        && $event->condition->status === HealthConditionStatus::ResolvedRestrictionCreated);
});

it('resolveWithoutRestriction() really dispatches DeclaredHealthConditionResolved on a genuine resolution', function () {
    Event::fake([DeclaredHealthConditionResolved::class]);
    $contact = Contact::factory()->create();
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);

    healthNotifRecorder()->resolveWithoutRestriction($condition, $reviewer, 'Nota.');

    Event::assertDispatched(DeclaredHealthConditionResolved::class, fn ($event) => $event->condition->id === $condition->id
        && $event->condition->status === HealthConditionStatus::ResolvedNoRestriction);
});

// ── Cableado real: el listener está realmente registrado ───────────────────

it('SendHealthReviewResolutionNotification is really registered as a listener of DeclaredHealthConditionResolved', function () {
    $rawListeners = app('events')->getRawListeners()[DeclaredHealthConditionResolved::class] ?? [];

    expect(collect($rawListeners))->toContain(SendHealthReviewResolutionNotification::class);
});
