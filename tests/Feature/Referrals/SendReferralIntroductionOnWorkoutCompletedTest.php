<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Referrals\Listeners\SendReferralIntroductionOnWorkoutCompleted;
use App\Referrals\Models\Referral;
use App\Referrals\Models\ReferralCode;
use App\Referrals\Models\ReferralReward;
use App\Referrals\Support\ReferralIntentClassifier;
use App\Training\Enums\TrainingAccessStatus;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Events\WorkoutSessionCompleted;
use App\Training\Support\ExecutionReportRecorder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Referral Introduction (ver docs/DECISIONS.md) — segundo consumidor real
 * de un evento de dominio ajeno a Referrals, mismo patrón exacto que
 * ApplyReferralRewardOnPaymentConfirmedTest.php: el listener se invoca
 * DIRECTAMENTE (`->handle($event)`), NO disparando el evento real vía
 * ExecutionReportRecorder — bajo la transacción envolvente de
 * RefreshDatabase, `ShouldDispatchAfterCommit` nunca ve un commit real. El
 * cableado real (dispatch + registro en AppServiceProvider) se verifica
 * aparte, con tests dedicados que sí lo comprueban de forma automatizada
 * (Event::fake() + reflection de listeners), no solo "por inspección".
 */

function introductionListener(): SendReferralIntroductionOnWorkoutCompleted
{
    return app(SendReferralIntroductionOnWorkoutCompleted::class);
}

function openWaWindowFor(Contact $contact): void
{
    Conversation::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'last_session_at' => now(),
    ]);
}

/**
 * @return array{0: Contact, 1: TrainingProfile, 2: WorkoutSession}
 */
function makeContactWithFirstCompletedWorkout(Tenant $tenant, string $phone = '573001112233'): array
{
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => $phone]);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);

    return [$contact, $profile, $session];
}

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
});

// ── 1. Primer entrenamiento completado -> se envía ─────────────────────────

it('sends the Referral Introduction after the first completed workout', function () {
    $tenant = Tenant::factory()->create(['referral_reward_days' => 3]);
    [$contact, $profile, $session] = makeContactWithFirstCompletedWorkout($tenant);
    openWaWindowFor($contact);

    introductionListener()->handle(new WorkoutSessionCompleted($session));

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'primer entrenamiento')
        && str_contains(data_get($request->data(), 'text.body', ''), '3 días')
        && str_contains(data_get($request->data(), 'text.body', ''), 'quiero invitar a un amigo'));

    // El marker vive en Contact, no en TrainingProfile — es estado de
    // comunicación/proactividad del programa de Referral, no del perfil de
    // entrenamiento.
    expect($contact->fresh()->referral_introduction_sent_at)->not->toBeNull();
});

// ── 2. Segundo entrenamiento completado -> NO se envía ─────────────────────

it('does NOT send after a second completed workout', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112234']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    openWaWindowFor($contact);

    $firstSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    introductionListener()->handle(new WorkoutSessionCompleted($firstSession));
    Http::assertSentCount(1);

    $secondSession = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    introductionListener()->handle(new WorkoutSessionCompleted($secondSession));

    // Ninguna llamada HTTP adicional — sigue en 1.
    Http::assertSentCount(1);
});

// ── 3. Completion duplicado/reprocesado -> NO se envía dos veces ──────────

it('is idempotent when the same completion event is processed twice', function () {
    $tenant = Tenant::factory()->create();
    [$contact, $profile, $session] = makeContactWithFirstCompletedWorkout($tenant, '573001112235');
    openWaWindowFor($contact);

    introductionListener()->handle(new WorkoutSessionCompleted($session));
    introductionListener()->handle(new WorkoutSessionCompleted($session)); // reprocesado

    Http::assertSentCount(1);
});

// ── 4/5/6. Multi-tenant: cada uno usa su propio referral_reward_days ──────

it('Tenant A uses its own referral_reward_days in the message', function () {
    $tenantA = Tenant::factory()->create(['referral_reward_days' => 5]);
    [$contactA, , $sessionA] = makeContactWithFirstCompletedWorkout($tenantA, '573005550001');
    openWaWindowFor($contactA);

    introductionListener()->handle(new WorkoutSessionCompleted($sessionA));

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '5 días'));
});

it('Tenant B uses its own (different) referral_reward_days in the message', function () {
    $tenantB = Tenant::factory()->create(['referral_reward_days' => 7]);
    [$contactB, , $sessionB] = makeContactWithFirstCompletedWorkout($tenantB, '573005550002');
    openWaWindowFor($contactB);

    introductionListener()->handle(new WorkoutSessionCompleted($sessionB));

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '7 días'));
    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '5 días'));
});

it('uses singular "día" when referral_reward_days is exactly 1', function () {
    $tenant = Tenant::factory()->create(['referral_reward_days' => 1]);
    [$contact, , $session] = makeContactWithFirstCompletedWorkout($tenant, '573005550003');
    openWaWindowFor($contact);

    introductionListener()->handle(new WorkoutSessionCompleted($session));

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '1 día adicional')
        && ! str_contains(data_get($request->data(), 'text.body', ''), '1 días'));
});

// ── 7. Un entrenamiento NO completado -> NO se envía ───────────────────────

it('does NOT send when the given session is not actually Completed', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112236']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    openWaWindowFor($contact);

    $scheduledSession = WorkoutSession::factory()->create(['contact_id' => $contact->id]); // default: Scheduled

    introductionListener()->handle(new WorkoutSessionCompleted($scheduledSession));

    Http::assertNothingSent();
});

// ── 8. No altera TrainingAccess ────────────────────────────────────────────

it('does not modify TrainingAccess in any way', function () {
    $tenant = Tenant::factory()->create();
    [$contact, , $session] = makeContactWithFirstCompletedWorkout($tenant, '573001112237');
    openWaWindowFor($contact);
    $access = TrainingAccess::factory()->for($contact)->create(['status' => TrainingAccessStatus::Trial, 'expires_at' => now()->addDays(5)]);
    $expiresAtBefore = $access->expires_at;
    $statusBefore = $access->status;
    $updatedAtBefore = $access->updated_at;

    introductionListener()->handle(new WorkoutSessionCompleted($session));

    $access->refresh();
    expect($access->expires_at->eq($expiresAtBefore))->toBeTrue();
    expect($access->status)->toBe($statusBefore);
    expect($access->updated_at->eq($updatedAtBefore))->toBeTrue();
});

// ── 9/10. No crea ReferralCode/Referral/ReferralReward por adelantado ─────

it('does not create ReferralCode, Referral, or ReferralReward', function () {
    $tenant = Tenant::factory()->create();
    [$contact, , $session] = makeContactWithFirstCompletedWorkout($tenant, '573001112238');
    openWaWindowFor($contact);

    introductionListener()->handle(new WorkoutSessionCompleted($session));

    expect(ReferralCode::where('contact_id', $contact->id)->exists())->toBeFalse();
    expect(Referral::count())->toBe(0);
    expect(ReferralReward::count())->toBe(0);
});

// ── Elegibilidad: programa desactivado ─────────────────────────────────────

it('does not send when the referral program is disabled for the tenant, and does not claim the marker either', function () {
    $tenant = Tenant::factory()->create(['referral_program_enabled' => false]);
    [$contact, , $session] = makeContactWithFirstCompletedWorkout($tenant, '573001112239');
    openWaWindowFor($contact);

    introductionListener()->handle(new WorkoutSessionCompleted($session));

    Http::assertNothingSent();
    // El gate corre ANTES del claim: un programa desactivado nunca debe
    // consumir el único "primer entrenamiento" de un Contact. Si más
    // adelante el programa se habilita, este Contact debe seguir siendo
    // elegible.
    expect($contact->fresh()->referral_introduction_sent_at)->toBeNull();
});

// ── Defensivo: sin TrainingProfile ─────────────────────────────────────────

it('still sends the Referral Introduction even when the Contact has no TrainingProfile', function () {
    // El marker y la elegibilidad viven en Contact/WorkoutSession — el
    // listener ya no conoce TrainingProfile en absoluto, así que su
    // ausencia no debe impedir el envío.
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112240']);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    openWaWindowFor($contact);

    introductionListener()->handle(new WorkoutSessionCompleted($session));

    Http::assertSentCount(1);
    expect($contact->fresh()->referral_introduction_sent_at)->not->toBeNull();
});

// ── 11. "quiero invitar a un amigo" sigue siendo Referral ──────────────────

it('the taught phrase "quiero invitar a un amigo" is still resolved by ReferralIntentClassifier', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new ReferralIntentClassifier;

    expect($classifier->classify(new App\Core\Messaging\ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new App\Core\Messaging\IngestedMessage('573001112233', 'quiero invitar a un amigo', 'wamid.1', 'text', null),
    )))->toBe(App\Core\Messaging\Intent::Referral);
});

// ── Cableado real: el evento se despacha desde ExecutionReportRecorder ────

it('ExecutionReportRecorder really dispatches WorkoutSessionCompleted when a session completes', function () {
    Event::fake([WorkoutSessionCompleted::class]);

    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $exercise = \App\Models\Exercise::factory()->create();
    $workoutExercise = \App\Models\WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 1, // evita el camino de "reporte parcial" — se reporta exactamente 1 serie
    ]);

    app(ExecutionReportRecorder::class)->record($session, [
        'reports' => [[
            'exercise_name' => null, 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 20, 'duration_seconds' => null]],
            'rpe' => null, 'note' => null, 'uncertain' => false, 'skip_reason' => null,
        ]],
    ]);

    Event::assertDispatched(WorkoutSessionCompleted::class, fn ($event) => $event->session->is($session->fresh()));
});

// ── Cableado real: el listener está realmente registrado ──────────────────

it('SendReferralIntroductionOnWorkoutCompleted is really registered as a listener of WorkoutSessionCompleted', function () {
    $rawListeners = app('events')->getRawListeners()[WorkoutSessionCompleted::class] ?? [];

    expect(collect($rawListeners))->toContain(SendReferralIntroductionOnWorkoutCompleted::class);
});
