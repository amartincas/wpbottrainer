<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingPreference;
use App\Models\TrainingPreferenceClarification;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\TrainingPreferenceClarificationStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Hito B3.1 (Estado conversacional para clarificaciones de preferencias) —
 * cobertura conversacional completa a través de `TrainingHandler::handle()`
 * (vía `ProcessWhatsAppMessage`), mismo rigor que `TrainingPreferenceConversationTest`.
 * Cubre la matriz mínima del encargo de implementación (Parte 10). Helpers
 * con prefijo "pendingConv" — propios de este archivo (mismo criterio que el
 * resto de la suite B3: cada archivo declara los suyos, nunca se comparten
 * funciones globales entre archivos de test).
 */
function pendingConvReadyContact(): Contact
{
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

function pendingConvSendMessage(Contact $contact, string $body, ?string $wamid = null): void
{
    $job = new ProcessWhatsAppMessage($contact->tenant, $contact->customer_phone, $body, $wamid ?? 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

function pendingConvCoachTurn(array $intents = [], array $requestedFocusTerms = []): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'safety_signal_text' => null, 'intents' => $intents, 'training_reply' => null,
        'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        'conversation_reinforcement_included' => false, 'requested_focus_terms' => $requestedFocusTerms,
    ])]]]];
}

function pendingConvOutboundBodies(): Collection
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body', ''));
}

/**
 * Catálogo real reproducido del E2E (Contact 26, staging): 6 variantes
 * "Sentadilla..." activas, exactamente la forma que produjo el hallazgo
 * original de MAX_CLARIFICATION_OPTIONS.
 */
function pendingConvSquatCatalog(): void
{
    foreach ([
        'Sentadilla con banda',
        'Sentadilla con peso corporal',
        'Sentadilla búlgara con mancuernas',
        'Máquina de sentadilla con cinturón Cuads',
        'Sentadilla de glúteos en máquina Smith',
        'Sentadillas sumo',
    ] as $name) {
        Exercise::factory()->create(['name' => $name, 'name_es' => $name, 'is_active' => true]);
    }
}

function pendingConvFakeHttp(array $coachTurn): void
{
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response($coachTurn),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);
}

// ── 1. preference -> clarify -> pending creada ──

it('1: an ambiguous Dislike declaration creates a PENDING clarification, never a TrainingPreference', function () {
    $contact = pendingConvReadyContact();
    pendingConvSquatCatalog();
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contact, 'No me gustan las sentadillas.');

    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);

    $pending = TrainingPreferenceClarification::activePendingFor($contact);
    expect($pending)->not->toBeNull();
    expect($pending->dimension)->toBe(PreferenceDimension::Exercise);
    expect($pending->original_text)->toBe('No me gustan las sentadillas.');
    expect($pending->total_matches)->toBe(6);
    expect($pending->presented_options)->toHaveCount(5);
});

// ── 2. pending + exact option -> preference creada ──

it('2: replying with an exact option shown resolves and persists a TrainingPreference', function () {
    $contact = pendingConvReadyContact();
    $exercise = Exercise::factory()->create(['name' => 'Sumo Squat', 'name_es' => 'Sentadilla sumo', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Banded Squat', 'name_es' => 'Sentadilla con banda', 'is_active' => true]);
    TrainingPreferenceClarification::factory()->create([
        'contact_id' => $contact->id,
        'original_text' => 'No me gustan las sentadillas.',
        'presented_options' => ['Sentadilla sumo', 'Sentadilla con banda'],
        'total_matches' => 2,
    ]);
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contact, 'Sentadilla sumo');

    $preference = TrainingPreference::where('contact_id', $contact->id)->first();
    expect($preference)->not->toBeNull();
    expect($preference->exercise_id)->toBe($exercise->id);
    expect($preference->original_text)->toBe('No me gustan las sentadillas.'); // turno 1, no la respuesta
});

// ── 3. pending + singular/plural -> preference creada ──

it('3: replying with a singular/plural variant of the shown option resolves via the sanctioned toggle', function () {
    $contact = pendingConvReadyContact();
    $exercise = Exercise::factory()->create(['name' => 'Burpee', 'name_es' => 'Burpee', 'is_active' => true]);
    TrainingPreferenceClarification::factory()->create(['contact_id' => $contact->id]);
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contact, 'burpees');

    $preference = TrainingPreference::where('contact_id', $contact->id)->first();
    expect($preference)->not->toBeNull();
    expect($preference->exercise_id)->toBe($exercise->id);
});

// ── 4. pending + opción no mostrada pero inequívoca -> preference creada ──

it('4: replying with an unambiguous option NOT among the 5 shown still resolves (real E2E case)', function () {
    $contact = pendingConvReadyContact();
    pendingConvSquatCatalog();
    $sumo = Exercise::where('name_es', 'Sentadillas sumo')->first();
    TrainingPreferenceClarification::factory()->create([
        'contact_id' => $contact->id,
        'original_candidate_term' => 'sentadillas',
        'presented_options' => ['Sentadilla con banda', 'Sentadilla con peso corporal', 'Sentadilla búlgara con mancuernas', 'Máquina de sentadilla con cinturón Cuads', 'Sentadilla de glúteos en máquina Smith'],
        'total_matches' => 6,
    ]);
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contact, 'Sentadillas sumo');

    $preference = TrainingPreference::where('contact_id', $contact->id)->first();
    expect($preference)->not->toBeNull();
    expect($preference->exercise_id)->toBe($sumo->id);
    expect(TrainingPreferenceClarification::activePendingFor($contact))->toBeNull();
});

// ── 5. pending + respuesta ambigua -> old abandoned + new pending ──

it('5: an ambiguous reply abandons the old pending and creates a new one, never a preference', function () {
    $contact = pendingConvReadyContact();
    Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Sumo Squat', 'name_es' => 'Sentadilla sumo', 'is_active' => true]);
    Exercise::factory()->create(['name' => 'Bulgarian Squat', 'name_es' => 'Sentadilla búlgara', 'is_active' => true]);
    $old = TrainingPreferenceClarification::factory()->create([
        'contact_id' => $contact->id,
        'original_candidate_term' => 'sentadillas',
        'original_text' => 'No me gustan las sentadillas.',
    ]);
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contact, 'sentadilla con salto');

    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);
    expect($old->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Abandoned);

    $new = TrainingPreferenceClarification::activePendingFor($contact);
    expect($new)->not->toBeNull();
    expect($new->id)->not->toBe($old->id);
    expect($new->original_candidate_term)->toBe('sentadilla con salto'); // la respuesta, nunca concatenada
    expect($new->original_text)->toBe('No me gustan las sentadillas.'); // se conserva de la pending original
});

// ── 6. pending + no match -> pending intacta ──

it('6: a reply matching nothing at all leaves the pending untouched and creates no preference', function () {
    $contact = pendingConvReadyContact();
    Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);
    $pending = TrainingPreferenceClarification::factory()->create(['contact_id' => $contact->id]);
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contact, 'malabares con antorchas');

    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);
    expect($pending->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Pending);
    expect($pending->fresh()->presented_options)->toBe($pending->presented_options); // sin cambios
});

// ── 7. pending + NewWorkoutRequest -> B2 normal, pending intacta ──

it('7: "Quiero otra rutina" with a pending active is excluded by $resolved and runs B2 normally', function () {
    $contact = pendingConvReadyContact();
    Exercise::factory()->count(4)->create(['is_active' => true]);
    WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => \App\Training\Enums\WorkoutSessionStatus::Scheduled]);
    $pending = TrainingPreferenceClarification::factory()->create(['contact_id' => $contact->id]);
    pendingConvFakeHttp(pendingConvCoachTurn(intents: ['new_workout_request']));

    pendingConvSendMessage($contact, 'Quiero otra rutina.');

    expect($pending->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Pending);
    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);
});

// ── 8. pending + RequestedFocus -> B1 normal, pending intacta ──

it('8: "Quiero entrenar piernas" with a pending active is excluded by $resolved (DeliverSession) and pending survives', function () {
    $contact = pendingConvReadyContact();
    Exercise::factory()->count(4)->create(['muscle_group' => 'legs', 'primary_muscle' => \App\Training\Enums\MuscleFocus::Quads, 'is_active' => true]);
    $pending = TrainingPreferenceClarification::factory()->create(['contact_id' => $contact->id]);
    pendingConvFakeHttp(pendingConvCoachTurn(intents: ['continue_training'], requestedFocusTerms: ['piernas']));

    pendingConvSendMessage($contact, 'Quiero entrenar piernas.');

    expect($pending->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Pending);
    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);
});

// ── 9. pending + nueva preference explícita -> old abandoned + new preference ──

it('9: a new unambiguous Dislike declaration abandons the old pending and persists directly', function () {
    $contact = pendingConvReadyContact();
    $exercise = Exercise::factory()->create(['name' => 'Bench Press', 'name_es' => 'Press de banca', 'is_active' => true]);
    $old = TrainingPreferenceClarification::factory()->create(['contact_id' => $contact->id]);
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contact, 'No me gusta el press de banca.');

    $preference = TrainingPreference::where('contact_id', $contact->id)->first();
    expect($preference)->not->toBeNull();
    expect($preference->exercise_id)->toBe($exercise->id);
    expect($old->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Abandoned);
});

// ── 10. pending + Safety -> Safety gana, pending intacta, sin preference ──

it('10: a Safety message with a pending active routes to Safety, never touches the pending or creates a preference', function () {
    $contact = pendingConvReadyContact();
    pendingConvSquatCatalog();
    $pending = TrainingPreferenceClarification::factory()->create(['contact_id' => $contact->id]);
    pendingConvFakeHttp(pendingConvCoachTurn());

    // Nota: "me operaron"/"cirugía reciente" colisionan con el vocabulario
    // de EMERGENCIA de SafetySignalDetector (Paso 1 de TrainingHandler::handle(),
    // precedencia absoluta sobre TODO, incluido B3) — ese caso escala y
    // corta el turno ANTES de llegar siquiera al clasificador B3, así que
    // no ejercería el camino category=Safety de B3 que este test busca
    // cubrir. Se usa el marcador de lesión cotidiana ("me duele"), ya
    // probado en TrainingPreferenceConversationTest Caso D como NO
    // interceptado por SafetySignalDetector.
    pendingConvSendMessage($contact, 'No puedo hacer sentadillas sumo porque me duele la rodilla.');

    expect($pending->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Pending);
    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);
    expect(\App\Models\DeclaredHealthCondition::where('contact_id', $contact->id)->count())->toBe(1);
});

// ── 11. pending expired -> expired + flujo normal ──

it('11: an expired pending is treated as inexistent — no resolution, normal flow proceeds', function () {
    $contact = pendingConvReadyContact();
    pendingConvSquatCatalog();
    $expired = TrainingPreferenceClarification::factory()->expired()->create(['contact_id' => $contact->id]);
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contact, 'Sentadillas sumo');

    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);
    expect($expired->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Expired);
});

// ── 12/13. pending consumida -> EXACTAMENTE una respuesta B3, executeTurnActions() nunca corre ──

it('12/13: consuming a pending sends EXACTLY one B3 reply, never the generic CoachService training_reply (the real E2E bug)', function () {
    $contact = pendingConvReadyContact();
    pendingConvSquatCatalog();
    TrainingPreferenceClarification::factory()->create([
        'contact_id' => $contact->id,
        'presented_options' => ['Sentadilla con banda', 'Sentadilla con peso corporal', 'Sentadilla búlgara con mancuernas', 'Máquina de sentadilla con cinturón Cuads', 'Sentadilla de glúteos en máquina Smith'],
        'total_matches' => 6,
    ]);
    // Mismo turno EXACTO reproducido en el E2E real: CoachService no
    // detecta ningún intent -> $resolved cae al fallback SendText.
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contact, 'Sentadillas sumo');

    $bodies = pendingConvOutboundBodies();
    expect($bodies)->toHaveCount(1);
    expect($bodies->first())->toContain('Anotado');
    expect($bodies->contains(fn ($b) => str_contains($b, 'sesión actual ya se completó')))->toBeFalse();
    expect($bodies->contains(fn ($b) => str_contains($b, 'No estoy segura de haber entendido')))->toBeFalse();

    // executeTurnActions() nunca corrió: ninguna WorkoutSession nueva.
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(0);
});

// ── 14. pending no consumida (no_match) -> CoachService normal ──

it('14: a non-response with a pending active still gets the normal CoachService reply', function () {
    $contact = pendingConvReadyContact();
    Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);
    TrainingPreferenceClarification::factory()->create(['contact_id' => $contact->id]);
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contact, 'malabares con antorchas');

    // El fallback normal (executeTurnActions() SÍ corrió) sigue enviándose.
    expect(pendingConvOutboundBodies())->toHaveCount(1);
    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);
});

// ── 15. duplicate WhatsApp message -> una sola resolución ──

it('15: processing the same inbound message twice never creates a duplicate preference nor errors', function () {
    $contact = pendingConvReadyContact();
    $exercise = Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);
    TrainingPreferenceClarification::factory()->create(['contact_id' => $contact->id, 'original_candidate_term' => 'sentadilla']);
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contact, 'sentadilla', 'wamid.DUP');
    pendingConvSendMessage($contact, 'sentadilla', 'wamid.DUP'); // reintento — el segundo ya no ve una pending activa

    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(1);
    expect(TrainingPreference::where('contact_id', $contact->id)->first()->exercise_id)->toBe($exercise->id);
});

// ── 16. concurrencia -> ver TrainingPreferenceClarificationLifecycleTest
// ("the database rejects a second PENDING row...") para el test del
// mecanismo real (índice único generado) que protege contra dos requests
// simultáneos — no reproducible de forma determinista a nivel de Feature
// test síncrono. ──

// ── 17. cross-contact isolation ──

it('17: resolving one contact\'s pending never touches another contact\'s pending', function () {
    $contactA = pendingConvReadyContact();
    $contactB = pendingConvReadyContact();
    Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);
    $pendingA = TrainingPreferenceClarification::factory()->create(['contact_id' => $contactA->id]);
    $pendingB = TrainingPreferenceClarification::factory()->create(['contact_id' => $contactB->id]);
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contactA, 'sentadilla');

    expect(TrainingPreference::where('contact_id', $contactA->id)->count())->toBe(1);
    expect(TrainingPreference::where('contact_id', $contactB->id)->count())->toBe(0);
    expect($pendingB->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Pending);
    expect($pendingA->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Resolved);
});

// ── 18. cross-tenant isolation ──

it('18: two contacts of different tenants never resolve each other\'s pending', function () {
    $contactA = pendingConvReadyContact();
    $tenantB = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id, 'customer_phone' => $contactA->customer_phone]);
    TrainingProfile::factory()->create(['contact_id' => $contactB->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contactB->id]);
    Exercise::factory()->create(['name' => 'Squat', 'name_es' => 'Sentadilla', 'is_active' => true]);
    $pendingB = TrainingPreferenceClarification::factory()->create(['contact_id' => $contactB->id]);
    pendingConvFakeHttp(pendingConvCoachTurn());

    pendingConvSendMessage($contactA, 'sentadilla');

    expect(TrainingPreference::where('contact_id', $contactA->id)->count())->toBe(0);
    expect($pendingB->fresh()->status)->toBe(TrainingPreferenceClarificationStatus::Pending);
});

// ── Bonus: el mismo gate también se aplica en el paso 4 (Main pendiente) ──

it('bonus: the pre-execute gate also applies on the ExecutionReportService path (paso 4, Main pendiente)', function () {
    $contact = pendingConvReadyContact();
    $exercise = Exercise::factory()->create(['name' => 'Deadlift', 'name_es' => 'Peso muerto', 'is_active' => true]);
    $mainExercise = Exercise::factory()->create(['name' => 'Bench', 'name_es' => 'Press de banca', 'is_active' => true]);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => \App\Training\Enums\WorkoutSessionStatus::Scheduled]);
    \App\Models\WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id,
        'exercise_id' => $mainExercise->id,
        'exercise_snapshot' => $mainExercise->toSnapshot(),
        'order' => 1,
        'phase' => \App\Training\Enums\WorkoutExercisePhase::Main,
        'prescribed_sets' => 3,
        'prescribed_reps' => 10,
        'delivered_at' => now()->subMinutes(1),
    ]);
    TrainingPreferenceClarification::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'safety_signal_text' => null, 'reports' => [], 'session_finished' => false, 'intents' => [],
            'training_reply' => null, 'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null,
            'reminder_confirmation' => null, 'requested_focus_terms' => [],
        ])]]]]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    pendingConvSendMessage($contact, 'peso muerto');

    $preference = TrainingPreference::where('contact_id', $contact->id)->first();
    expect($preference)->not->toBeNull();
    expect($preference->exercise_id)->toBe($exercise->id);
});
