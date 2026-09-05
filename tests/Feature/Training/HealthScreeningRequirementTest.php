<?php

use App\Core\Alerts\AlertService;
use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Models\TrainingProfile;
use App\Models\TrainingRestriction;
use App\Models\User;
use App\Training\Enums\BodyRegion;
use App\Training\Enums\HealthConditionCategory;
use App\Training\Enums\HealthConditionStatus;
use App\Training\Enums\RestrictionStatus;
use App\Training\Onboarding\Requirements\HealthScreeningRequirement;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\DeclaredHealthConditionRecorder;
use App\Training\Support\FunctionalLimitationCanonicalMapper;
use Illuminate\Support\Facades\Http;

/**
 * Bloque 5 — tests de dominio de HealthScreeningRequirement, según el ciclo
 * conversacional aprobado (1 o 2 turnos, nunca más).
 */
function healthScreeningRequirement(): HealthScreeningRequirement
{
    return new HealthScreeningRequirement(
        new DeclaredHealthConditionRecorder(new BodyRegionCanonicalMapper, new FunctionalLimitationCanonicalMapper),
        app(AlertService::class),
    );
}

function emptyHealthScreeningValues(array $overrides = []): array
{
    return array_merge([
        'health_declaration_category' => null,
        'health_condition_text' => null,
        'functional_limitation_text' => null,
    ], $overrides);
}

it('exposes the correct key, extractedKeys and blocking status', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $requirement = healthScreeningRequirement();

    expect($requirement->key())->toBe('health_screening');
    expect($requirement->extractedKeys())->toBe(['health_declaration_category', 'health_condition_text', 'functional_limitation_text']);
    expect($requirement->isBlocking($profile, $contact))->toBeTrue();
});

// ── 1: sin condición ──

it('1: "ninguna" closes the screening and creates no DeclaredHealthCondition', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    healthScreeningRequirement()->apply($contact, $profile, emptyHealthScreeningValues(['health_condition_text' => '']));

    expect($profile->fresh()->health_screening_asked)->toBeTrue();
    expect(DeclaredHealthCondition::count())->toBe(0);
});

it('an unanswered turn does not close the screening and creates nothing', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    healthScreeningRequirement()->apply($contact, $profile, emptyHealthScreeningValues());

    expect($profile->fresh()->health_screening_asked)->toBeFalse();
    expect(DeclaredHealthCondition::count())->toBe(0);
});

// ── 2: condición explícita sin limitación funcional ──

it('2: an explicit condition without functional detail creates a pending DeclaredHealthCondition and does NOT close the screening yet', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    healthScreeningRequirement()->apply($contact, $profile, emptyHealthScreeningValues([
        'health_condition_text' => 'tengo una lesión de hombro',
    ]));

    $condition = DeclaredHealthCondition::first();
    expect($condition)->not->toBeNull();
    expect($condition->status)->toBe(HealthConditionStatus::PendingReview);
    expect($condition->category)->toBe(HealthConditionCategory::PossibleInjury);
    expect($condition->functional_limitation_text)->toBeNull();
    expect(TrainingRestriction::count())->toBe(0);
    // Sigue esperando la pregunta de seguimiento — la conversación no cierra todavía.
    expect($profile->fresh()->health_screening_asked)->toBeFalse();
});

// ── 3: condición + limitación funcional explícita ──

it('3: condition + explicit functional limitation preserves the literal text and never confirms a restriction', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    healthScreeningRequirement()->apply($contact, $profile, emptyHealthScreeningValues([
        'health_condition_text' => 'tengo una lesión en el hombro y no puedo levantar el brazo por encima de la cabeza',
        'functional_limitation_text' => 'no puedo levantar el brazo por encima de la cabeza',
    ]));

    $condition = DeclaredHealthCondition::first();
    expect($condition->functional_limitation_text)->toBe('no puedo levantar el brazo por encima de la cabeza');
    expect($condition->status)->toBe(HealthConditionStatus::PendingReview);
    expect(TrainingRestriction::count())->toBe(0);
    // Vino con detalle funcional en el mismo mensaje: cierra de inmediato.
    expect($profile->fresh()->health_screening_asked)->toBeTrue();
});

// ── 4/5: el mapper es solo una sugerencia, nunca una autoridad ──

it('4/5: functional limitation mapper result is only a suggestion attached to the declaration, never a restriction', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    healthScreeningRequirement()->apply($contact, $profile, emptyHealthScreeningValues([
        'health_condition_text' => 'me molesta al mover el brazo',
        'functional_limitation_text' => 'una frase no catalogada',
    ]));

    $condition = DeclaredHealthCondition::first();
    // Catálogo vacío por diseño (ver FunctionalLimitationCanonicalMapper) — null.
    expect($condition->suggested_body_region)->toBeNull();
    expect(TrainingRestriction::count())->toBe(0);
});

// ── Ciclo de 2 turnos: condición sin detalle -> seguimiento vago (caso D) ──

it('follow-up cycle: a condition without detail, then a vague follow-up reply, closes the conversation without inferring anything', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);
    $requirement = healthScreeningRequirement();

    // Turno 1.
    $requirement->apply($contact, $profile, emptyHealthScreeningValues(['health_condition_text' => 'me duele el hombro']));
    $profile->refresh();
    expect($profile->health_screening_asked)->toBeFalse();
    expect(DeclaredHealthCondition::count())->toBe(1);

    // questionContext() ahora debe ofrecer la pregunta de SEGUIMIENTO.
    $context = $requirement->questionContext($profile, $contact->fresh());
    expect($context->fallbackQuestion)->toContain('movimiento específico');

    // Turno 2: respuesta vaga — "No sé, simplemente me duele."
    $requirement->apply($contact, $profile, emptyHealthScreeningValues(['health_condition_text' => 'no sé, simplemente me duele']));
    $profile->refresh();

    expect($profile->health_screening_asked)->toBeTrue(); // cierra — nunca se insiste una tercera vez
    expect(DeclaredHealthCondition::count())->toBe(2); // Bloque 2 append-only: dos hechos, no una edición
    expect(TrainingRestriction::count())->toBe(0);
});

it('questionContext presents the initial question before any declaration, and the follow-up once a condition is pending review', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $requirement = healthScreeningRequirement();

    $initial = $requirement->questionContext($profile, $contact);
    expect($initial->fallbackQuestion)->toContain('lesión, dolor, molestia');

    DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]); // pending_review por defecto

    $followUp = $requirement->questionContext($profile, $contact);
    expect($followUp->fallbackQuestion)->toContain('movimiento específico');
});

// ── 7: recovery ──

it('7: a recovery declaration creates a new record and never modifies an existing restriction', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $existing = TrainingRestriction::factory()->create([
        'contact_id' => $contact->id,
        'status' => RestrictionStatus::Confirmed,
        'body_region' => BodyRegion::Shoulder,
    ]);

    healthScreeningRequirement()->apply($contact, $profile, emptyHealthScreeningValues([
        'health_condition_text' => 'ya estoy recuperado',
        'health_declaration_category' => 'possible_recovery',
    ]));

    $condition = DeclaredHealthCondition::first();
    expect($condition->category)->toBe(HealthConditionCategory::PossibleRecovery);
    expect($condition->status)->toBe(HealthConditionStatus::PendingReview);

    $existing->refresh();
    expect($existing->status)->toBe(RestrictionStatus::Confirmed);
    expect($existing->body_region)->toBe(BodyRegion::Shoulder);
});

// ── 8: professional_indication ──

it('8: a professional_indication declaration stays pending review — no auto-confirmation', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    healthScreeningRequirement()->apply($contact, $profile, emptyHealthScreeningValues([
        'health_condition_text' => 'mi fisioterapeuta me indicó que no haga ejercicios por encima de la cabeza',
        'health_declaration_category' => 'professional_indication',
        'functional_limitation_text' => 'no haga ejercicios por encima de la cabeza',
    ]));

    $condition = DeclaredHealthCondition::first();
    expect($condition->category)->toBe(HealthConditionCategory::ProfessionalIndication);
    expect($condition->status)->toBe(HealthConditionStatus::PendingReview);
    expect(TrainingRestriction::count())->toBe(0);
});

// ── 9/10: isSatisfied() — solo screening conversacional, nunca autorización técnica ──

it('9: isSatisfied() can be true while a declaration remains pending review — screening conversacional vs. autorización técnica son estados distintos', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);
    $requirement = healthScreeningRequirement();

    $requirement->apply($contact, $profile, emptyHealthScreeningValues([
        'health_condition_text' => 'lesión de hombro',
        'functional_limitation_text' => 'no puedo levantar el brazo por encima de la cabeza',
    ]));
    $profile->refresh();

    expect($requirement->isSatisfied($profile, $contact))->toBeTrue();
    expect(DeclaredHealthCondition::hasPendingReviewFor($contact->id))->toBeTrue();
});

it('10: isSatisfied() is false when nothing has been asked/answered yet', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    expect(healthScreeningRequirement()->isSatisfied($profile, $contact))->toBeFalse();
});

// ── 20/21: reutilización de la infraestructura de alertas existente ──

it('20/21: a new pending declaration triggers an admin alert via the existing WhatsApp infrastructure, never to the client', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    $contact = Contact::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => true, 'phone' => '573009998888']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    healthScreeningRequirement()->apply($contact, $profile, emptyHealthScreeningValues([
        'health_condition_text' => 'tengo una lesión de hombro',
    ]));

    Http::assertSent(fn ($request) => data_get($request->data(), 'to') === $admin->phone);
    Http::assertNotSent(fn ($request) => data_get($request->data(), 'to') === $contact->customer_phone);
});

// ── 22: un fallo de alerta no afecta el estado de la declaración ──

it('22: an alert delivery failure never blocks the declaration or changes its state', function () {
    // Sin Http::fake: WhatsAppService intentará una petición real y fallará
    // (o, sin credenciales de prueba, simplemente no hay super admins con
    // teléfono, por lo que WhatsAppAdminAlertChannel::supports() ya
    // devuelve false) — en cualquier caso, la declaración debe quedar
    // registrada igual.
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    healthScreeningRequirement()->apply($contact, $profile, emptyHealthScreeningValues([
        'health_condition_text' => 'tengo una lesión de hombro',
    ]));

    $condition = DeclaredHealthCondition::first();
    expect($condition)->not->toBeNull();
    expect($condition->status)->toBe(HealthConditionStatus::PendingReview);
});
