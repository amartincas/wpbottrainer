<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Core\Messaging\Router;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\Reminder;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Payments\Enums\PaymentStatus;

/**
 * Precedencia de Intents (ver docs/DECISIONS.md) — prueba el Router REAL,
 * resuelto desde el container con los tiers registrados en
 * AppServiceProvider, no clasificadores aislados. Es exactamente el bug
 * real reportado (contact_id=28-style: "Quiero invitar a un amigo" con una
 * WorkoutSession pendiente terminaba en Training, nunca en Referral) y su
 * corrección: una señal EXPLÍCITA de un dominio (Tier 1) siempre gana sobre
 * una señal puramente CONTEXTUAL de otro dominio (Tier 2), sin importar cuál
 * de las 4 condiciones contextuales de Training esté activa, ni si es
 * Training o Payment el que la tiene.
 */
function precedenceContext(Tenant $tenant, ?string $body, string $from = '573001112233'): ExecutionContext
{
    return new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage($from, $body, 'wamid.1', 'text', null),
    );
}

function makeContactWithIncompleteOnboarding(Tenant $tenant, string $phone): Contact
{
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => $phone]);
    TrainingProfile::factory()->incomplete()->create(['contact_id' => $contact->id]);

    return $contact;
}

function makeContactWithPendingWorkoutSession(Tenant $tenant, string $phone): Contact
{
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => $phone]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->create(['contact_id' => $contact->id]); // default: Scheduled

    return $contact;
}

function makeContactAwaitingFirstWorkout(Tenant $tenant, string $phone): Contact
{
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => $phone]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]); // Active, sin WorkoutSession

    return $contact;
}

function makeContactAwaitingReminderResponse(Tenant $tenant, string $phone): Contact
{
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => $phone]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    Reminder::factory()->awaitingResponse()->create(['contact_id' => $contact->id]);

    return $contact;
}

function makeContactWithOpenPayment(Tenant $tenant, string $phone): Contact
{
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => $phone]);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Pending]);

    return $contact;
}

// ── A/B/C/D — explícito de un dominio gana sobre contextual de otro ────────

it('A: Training contextual (pending workout) + Referral explícito -> Referral', function () {
    $tenant = Tenant::factory()->create();
    makeContactWithPendingWorkoutSession($tenant, '573001112233');

    $intent = app(Router::class)->route(precedenceContext($tenant, 'Quiero invitar a un amigo', '573001112233'));

    expect($intent)->toBe(Intent::Referral);
});

/**
 * Reproducción exacta de un hallazgo real de staging (ver docs/DECISIONS.md):
 * Contact con onboarding incompleto Y WorkoutSession pendiente A LA VEZ
 * (ambas condiciones contextuales de Training activas simultáneamente,
 * estado real confirmado del contacto que reportó el caso) enviando
 * "Quiero referenciar un amigo" — antes de agregar las frases de
 * "referenciar" a ReferralIntentClassifier, esto clasificaba como
 * Training y terminaba en el stub fijo de "membership_status"
 * (ConversationTurnResolver::COMMERCIAL_STUB). Debe ganar Referral.
 */
it('reproduces the real staging case: incomplete onboarding + pending workout together + "Quiero referenciar un amigo" -> Referral', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->incomplete()->create(['contact_id' => $contact->id]);
    WorkoutSession::factory()->create(['contact_id' => $contact->id]); // default: Scheduled

    $intent = app(Router::class)->route(precedenceContext($tenant, 'Quiero referenciar un amigo', '573001112233'));

    expect($intent)->toBe(Intent::Referral);
});

it('B: Training contextual (pending workout) + Payment explícito -> Payment', function () {
    $tenant = Tenant::factory()->create();
    makeContactWithPendingWorkoutSession($tenant, '573001112233');

    $intent = app(Router::class)->route(precedenceContext($tenant, 'Quiero pagar', '573001112233'));

    expect($intent)->toBe(Intent::Payment);
});

it('C: Payment contextual (pago abierto) + Referral explícito -> Referral', function () {
    $tenant = Tenant::factory()->create();
    makeContactWithOpenPayment($tenant, '573001112233');

    $intent = app(Router::class)->route(precedenceContext($tenant, 'Dame mi código', '573001112233'));

    expect($intent)->toBe(Intent::Referral);
});

it('D: Training contextual (pending workout) + CustomerCare explícito (escalamiento) -> CustomerCare', function () {
    $tenant = Tenant::factory()->create();
    makeContactWithPendingWorkoutSession($tenant, '573001112233');

    $intent = app(Router::class)->route(precedenceContext($tenant, 'Necesito ayuda', '573001112233'));

    expect($intent)->toBe(Intent::CustomerCare);
});

// ── E — las 4 condiciones contextuales de Training, cada una probada
//        contra Referral explícito y contra Payment explícito ───────────

dataset('training_contextual_conditions', [
    'incomplete onboarding' => fn (Tenant $tenant, string $phone) => makeContactWithIncompleteOnboarding($tenant, $phone),
    'pending workout session' => fn (Tenant $tenant, string $phone) => makeContactWithPendingWorkoutSession($tenant, $phone),
    'active access awaiting first workout' => fn (Tenant $tenant, string $phone) => makeContactAwaitingFirstWorkout($tenant, $phone),
    'awaiting reminder response' => fn (Tenant $tenant, string $phone) => makeContactAwaitingReminderResponse($tenant, $phone),
]);

it('E: cada condición contextual de Training + Referral explícito -> Referral', function (Closure $setup) {
    $tenant = Tenant::factory()->create();
    $setup($tenant, '573001112233');

    $intent = app(Router::class)->route(precedenceContext($tenant, 'Dame mi código de referido', '573001112233'));

    expect($intent)->toBe(Intent::Referral);
})->with('training_contextual_conditions');

it('E: cada condición contextual de Training + Payment explícito -> Payment', function (Closure $setup) {
    $tenant = Tenant::factory()->create();
    $setup($tenant, '573001112233');

    $intent = app(Router::class)->route(precedenceContext($tenant, 'Quiero pagar', '573001112233'));

    expect($intent)->toBe(Intent::Payment);
})->with('training_contextual_conditions');

// ── F — contextual puro: sin señal explícita, el contexto sigue
//        determinando Training (comportamiento legítimo preservado) ──────

it('F: "Ya terminé" con WorkoutSession pendiente -> Training', function () {
    $tenant = Tenant::factory()->create();
    makeContactWithPendingWorkoutSession($tenant, '573001112233');

    expect(app(Router::class)->route(precedenceContext($tenant, 'Ya terminé', '573001112233')))->toBe(Intent::Training);
});

it('F: "10x40" con WorkoutSession pendiente -> Training', function () {
    $tenant = Tenant::factory()->create();
    makeContactWithPendingWorkoutSession($tenant, '573001112233');

    expect(app(Router::class)->route(precedenceContext($tenant, '10x40', '573001112233')))->toBe(Intent::Training);
});

it('F: "Continuemos" con acceso activo esperando primer entrenamiento -> Training', function () {
    $tenant = Tenant::factory()->create();
    makeContactAwaitingFirstWorkout($tenant, '573001112233');

    expect(app(Router::class)->route(precedenceContext($tenant, 'Continuemos', '573001112233')))->toBe(Intent::Training);
});

// ── G — sin ningún contexto activo, esos mismos mensajes NO se vuelven
//        Training mágicamente (no contienen ninguna keyword explícita) ───

it('G: "Ya terminé"/"10x40"/"Continuemos" sin ningún contexto activo -> FallbackChat', function () {
    $tenant = Tenant::factory()->create();
    // Contact sin TrainingProfile/WorkoutSession/TrainingAccess/Reminder.
    Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);

    foreach (['Ya terminé', '10x40', 'Continuemos'] as $body) {
        expect(app(Router::class)->route(precedenceContext($tenant, $body, '573001112233')))->toBe(Intent::FallbackChat);
    }
});

// ── H — colisión con FAQ: la explícita de dominio (Referral) sigue
//        ganando dentro del propio Tier 1, FAQ es la última del tier ─────

it('H: "¿Cómo puedo referir a un amigo?" contiene señal FAQ ("cómo") y señal Referral ("referir") -> Referral', function () {
    $tenant = Tenant::factory()->create();
    Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);

    $intent = app(Router::class)->route(precedenceContext($tenant, '¿Cómo puedo referir a un amigo?', '573001112233'));

    expect($intent)->toBe(Intent::Referral);
});

it('H: "¿Cómo pago?" contiene señal FAQ ("cómo") y señal Payment ("cómo pago") -> Payment', function () {
    $tenant = Tenant::factory()->create();
    Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);

    $intent = app(Router::class)->route(precedenceContext($tenant, '¿Cómo pago?', '573001112233'));

    expect($intent)->toBe(Intent::Payment);
});

it('H: una pregunta puramente informativa, sin ninguna otra señal de dominio -> CustomerCare (FAQ)', function () {
    $tenant = Tenant::factory()->create();
    Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);

    $intent = app(Router::class)->route(precedenceContext($tenant, '¿Cuál es el horario?', '573001112233'));

    expect($intent)->toBe(Intent::CustomerCare);
});
