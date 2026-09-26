<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\TrainingProfile;
use App\Models\User;
use App\Training\Enums\SafetyStatus;
use App\Training\Events\TrainingProfileSafetyFlagCleared;
use App\Training\Listeners\SendSafetyReviewResolutionNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Hito O1 (Notificación proactiva de revisión de salud) — mismo patrón de
 * test que DeclaredHealthConditionResolutionNotificationTest.php /
 * SendReferralIntroductionOnWorkoutCompletedTest.php: listener invocado
 * directamente para probar su comportamiento; cableado real (dispatch +
 * registro) probado aparte.
 */
function safetyNotifListener(): SendSafetyReviewResolutionNotification
{
    return app(SendSafetyReviewResolutionNotification::class);
}

function openWaWindowForSafetyNotif(Contact $contact): void
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

// ── H. clearSafetyFlag() -> notificación, eventKey e idempotencyKey correctos ──

it('H: end-to-end — clearSafetyFlag() on a genuinely flagged profile sends the safety-resolved notification', function () {
    $contact = Contact::factory()->create();
    openWaWindowForSafetyNotif($contact);
    $profile = TrainingProfile::factory()->flaggedForSafetyReview('chest_pain')->create(['contact_id' => $contact->id]);
    $reviewer = User::factory()->create(['is_super_admin' => true]);

    $profile->clearSafetyFlag($reviewer, 'Consultó con su médico, autorizado a continuar.');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'ya revisamos tu caso'));
});

it('does not dispatch anything when clearSafetyFlag() is called on a profile that was already Normal', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'safety_status' => SafetyStatus::Normal]);
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    Event::fake([TrainingProfileSafetyFlagCleared::class]);

    $profile->clearSafetyFlag($reviewer, 'Nunca estuvo flagged.');

    Event::assertNotDispatched(TrainingProfileSafetyFlagCleared::class);
});

// ── idempotencyKey usa el timestamp ANTERIOR al clear, distinguiendo ciclos repetidos ──

it('the idempotencyKey uses the previous safety_flagged_at timestamp, producing distinct keys across separate flag->clear cycles', function () {
    $contact = Contact::factory()->create();
    openWaWindowForSafetyNotif($contact);
    $profile = TrainingProfile::factory()->flaggedForSafetyReview('chest_pain')->create(['contact_id' => $contact->id]);
    $reviewer = User::factory()->create(['is_super_admin' => true]);

    $profile->clearSafetyFlag($reviewer, 'Primer ciclo resuelto.');
    Http::assertSentCount(1);

    // Avanza el reloj de prueba: safety_flagged_at tiene precisión de
    // segundo (columna datetime) — sin este salto, un segundo ciclo
    // ejecutado en la misma prueba (misma fracción de segundo real) podría
    // coincidir con el primero y colisionar en la idempotencyKey, lo cual
    // NO sería un fallo del mecanismo en producción real (los ciclos
    // reales nunca ocurren en el mismo segundo), solo un artefacto de
    // velocidad de ejecución del test.
    $this->travel(2)->seconds();

    // Segundo ciclo completo: nueva señal, nueva limpieza — un timestamp
    // distinto de safety_flagged_at debe producir una idempotencyKey
    // distinta, y por lo tanto una SEGUNDA entrega real (nunca bloqueada
    // por la clave del primer ciclo).
    $profile->flagForSafetyReview('chest_pain');
    $profile->refresh();
    $profile->clearSafetyFlag($reviewer, 'Segundo ciclo resuelto.');

    Http::assertSentCount(2);
});

// ── Cableado real: el evento se despacha desde el modelo ───────────────────

it('clearSafetyFlag() really dispatches TrainingProfileSafetyFlagCleared with the previous timestamp captured before clearing', function () {
    Event::fake([TrainingProfileSafetyFlagCleared::class]);
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->flaggedForSafetyReview('chest_pain')->create(['contact_id' => $contact->id]);
    $flaggedAt = $profile->safety_flagged_at;
    $reviewer = User::factory()->create(['is_super_admin' => true]);

    $profile->clearSafetyFlag($reviewer, 'Nota.');

    Event::assertDispatched(TrainingProfileSafetyFlagCleared::class, fn ($event) => $event->profile->id === $profile->id
        && $event->previousFlaggedAt?->eq($flaggedAt));
});

// ── Cableado real: el listener está realmente registrado ───────────────────

it('SendSafetyReviewResolutionNotification is really registered as a listener of TrainingProfileSafetyFlagCleared', function () {
    $rawListeners = app('events')->getRawListeners()[TrainingProfileSafetyFlagCleared::class] ?? [];

    expect(collect($rawListeners))->toContain(SendSafetyReviewResolutionNotification::class);
});
