<?php

use App\Models\Contact;
use App\Models\TrainingProfile;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\SplitType;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\OnboardingRequirementRegistry;
use App\Training\Onboarding\QuestionContext;
use App\Training\Onboarding\Requirements\EquipmentRequirement;
use App\Training\Onboarding\Requirements\NameRequirement;
use App\Training\Onboarding\Requirements\PhysicalStatsRequirement;
use App\Training\Onboarding\Requirements\SessionsPerWeekRequirement;

/**
 * Bloque 4 — tests de dominio de OnboardingRequirement/Registry, fuera del
 * flujo E2E completo (que vive en TrainingConversationFlowTest.php).
 */
function unsatisfiedOpportunisticProfile(Contact $contact, array $overrides = []): TrainingProfile
{
    // La factory por defecto deja los 3 requirements de Capa 2 YA
    // satisfechos (primary_focus=[], sessions_per_week=3,
    // physical_stats_asked=true) — estos tests de política de turnos
    // necesitan partir de "todavía sin responder".
    return TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'primary_focus' => null,
        'sessions_per_week' => null,
        'physical_stats_asked' => false,
    ], $overrides));
}

// ── A ──

it('A: the registry returns requirements in registration order', function () {
    $keys = array_map(fn (OnboardingRequirement $r) => $r->key(), app(OnboardingRequirementRegistry::class)->all());

    expect($keys)->toBe([
        'name', 'goal', 'experience_level', 'training_location',
        'available_equipment', 'restrictions', 'sessions_per_week',
        'primary_focus', 'physical_stats',
    ]);
});

// ── B ──

it('B: a satisfied requirement is never returned as pending', function () {
    $contact = Contact::factory()->create(['customer_name' => 'Ana']);
    // incomplete(): deja el resto de campos bloqueantes en null — la
    // factory por defecto los rellena aleatoriamente, lo que enmascararía
    // este test (todo ya "satisfecho" por accidente).
    $profile = TrainingProfile::factory()->incomplete()->create(['contact_id' => $contact->id, 'goal' => TrainingGoal::BuildMuscle]);

    $pending = app(OnboardingRequirementRegistry::class)->firstPendingBlocking($profile, $contact);

    expect($pending->key())->toBe('experience_level'); // name y goal ya satisfechos
});

// ── C: extensibilidad LEGO (test positivo — ver también el test de arquitectura para la ausencia de acoplamiento) ──

class FakeExtraOnboardingRequirementForTest implements OnboardingRequirement
{
    public function key(): string
    {
        return 'fake_field';
    }

    public function extractedKeys(): array
    {
        return ['fake_field'];
    }

    public function isBlocking(TrainingProfile $profile, Contact $contact): bool
    {
        return true;
    }

    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool
    {
        return false;
    }

    public function validate(array $rawValues): array
    {
        return $rawValues;
    }

    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void {}

    public function onAsked(Contact $contact, TrainingProfile $profile): void {}

    public function questionContext(TrainingProfile $profile, Contact $contact): QuestionContext
    {
        return new QuestionContext('fake_field', 'demo', true, 'string', null, [], 'Pregunta de prueba');
    }
}

it('C: registering a brand-new Requirement class works via one class + one registry entry, without touching OnboardingConversationService', function () {
    $registry = new OnboardingRequirementRegistry(app(), [
        NameRequirement::class,
        FakeExtraOnboardingRequirementForTest::class,
    ]);

    $contact = Contact::factory()->create(['customer_name' => 'Ana']); // "name" ya satisfecho
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $pending = $registry->firstPendingBlocking($profile, $contact);

    expect($pending)->toBeInstanceOf(FakeExtraOnboardingRequirementForTest::class);
});

// ── D ──

it('D: questionContext() never contains the final WhatsApp text — only structured context', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    foreach (app(OnboardingRequirementRegistry::class)->all() as $requirement) {
        $context = $requirement->questionContext($profile, $contact);

        expect($context)->toBeInstanceOf(QuestionContext::class);
        expect($context->key)->toBe($requirement->key());
        expect($context->blocking)->toBe($requirement->isBlocking($profile, $contact));
        expect($context->purpose)->not->toBe('');
    }
});

// ── G ──

it('G: a single extracted payload satisfies several requirements at once, without extra questions', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'goal' => null, 'experience_level' => null, 'training_location' => null, 'available_equipment' => null]);

    app(OnboardingRequirementRegistry::class)->applyExtracted($contact, $profile, [
        'training_location' => 'home',
        'available_equipment' => ['dumbbells', 'resistance_bands'],
        'experience_level' => 'beginner',
    ]);

    $fresh = $profile->fresh();
    expect($fresh->training_location)->toBe(TrainingLocation::Home);
    expect($fresh->available_equipment)->toBe(['dumbbells', 'resistance_bands']);
    expect($fresh->experience_level)->toBe(ExperienceLevel::Beginner);
});

// ── I / R ──

it('I/R: PrimaryFocus (and sessions_per_week/physical_stats) never block — a profile missing only those is onboarding-complete', function () {
    $contact = Contact::factory()->create(['customer_name' => 'Ana']);
    $profile = TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'goal' => TrainingGoal::BuildMuscle,
        'experience_level' => ExperienceLevel::Beginner,
        'training_location' => TrainingLocation::Home,
        'available_equipment' => [],
        'restrictions' => [],
        'primary_focus' => null,
        'sessions_per_week' => null,
        'physical_stats_asked' => false,
    ]);

    expect(app(OnboardingRequirementRegistry::class)->isOnboardingComplete($profile, $contact))->toBeTrue();
});

// ── L: fix del bug real "tengo de todo" ──

it('L: EquipmentRequirement is satisfied by equipment_fully_equipped alone, without available_equipment', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'equipment_fully_equipped' => true, 'available_equipment' => null]);

    expect((new EquipmentRequirement)->isSatisfied($profile, $contact))->toBeTrue();
});

it('EquipmentRequirement is NOT satisfied when neither signal is present', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'equipment_fully_equipped' => false, 'available_equipment' => null]);

    expect((new EquipmentRequirement)->isSatisfied($profile, $contact))->toBeFalse();
});

// ── M ──

it('M: PhysicalStatsRequirement is satisfied purely by physical_stats_asked, regardless of actual values', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create([
        'contact_id' => $contact->id, 'physical_stats_asked' => true,
        'age' => null, 'sex' => null, 'weight_kg' => null, 'height_cm' => null,
    ]);

    expect((new PhysicalStatsRequirement)->isSatisfied($profile, $contact))->toBeTrue();
});

it('M: onAsked() marks physical_stats_asked, idempotently', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'physical_stats_asked' => false]);

    (new PhysicalStatsRequirement)->onAsked($contact, $profile);
    expect($profile->fresh()->physical_stats_asked)->toBeTrue();

    // Idempotente: llamarlo de nuevo no falla ni cambia nada.
    (new PhysicalStatsRequirement)->onAsked($contact, $profile->fresh());
    expect($profile->fresh()->physical_stats_asked)->toBeTrue();
});

// ── N ──

it('N: NameRequirement never overwrites an existing customer_name', function () {
    $contact = Contact::factory()->create(['customer_name' => 'Ana']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    (new NameRequirement)->apply($contact, $profile, ['name' => 'Otro Nombre']);

    expect($contact->fresh()->customer_name)->toBe('Ana');
});

// ── O ──

it('O: SessionsPerWeekRequirement::apply() derives split_type deterministically, without AI', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'split_type' => SplitType::FullBody]);

    (new SessionsPerWeekRequirement)->apply($contact, $profile, ['sessions_per_week' => 5]);

    $fresh = $profile->fresh();
    expect($fresh->sessions_per_week)->toBe(5);
    expect($fresh->split_type)->toBe(SplitType::PushPullLegs);
});

// ── Política de turnos progresivos DEFINITIVA (corregida — ver docs/DECISIONS.md D047):
// turno < 3 => null, SIEMPRE, sin importar el estado de blocking;
// turno >= 3 => primer oportunista no satisfecho, si existe. ──

it('turn 1 never offers a secondary opportunistic invitation, regardless of blocking state', function () {
    $contact = Contact::factory()->create();

    // Caso 1: blocking todavía pendiente (el único alcanzable en producción
    // desde TrainingHandler).
    $pending = TrainingProfile::factory()->incomplete()->create(['contact_id' => $contact->id]);
    expect(app(OnboardingRequirementRegistry::class)->secondaryOpportunisticFor(1, $pending, $contact))->toBeNull();

    // Caso 2: blocking ya completo (solo alcanzable de forma artificial,
    // pero el umbral es puramente por turno — debe seguir siendo null).
    $contact2 = Contact::factory()->create();
    $complete = unsatisfiedOpportunisticProfile($contact2);
    expect(app(OnboardingRequirementRegistry::class)->secondaryOpportunisticFor(1, $complete, $contact2))->toBeNull();
});

it('turn 2 STILL never offers a secondary opportunistic invitation, regardless of blocking state — the corrected threshold', function () {
    $contact = Contact::factory()->create();

    // Este es el bug real encontrado: con el umbral anterior (turnNumber < 2),
    // el turno 2 con blocking pendiente SÍ ofrecía un oportunista. Ahora no.
    $pending = TrainingProfile::factory()->incomplete()->create(['contact_id' => $contact->id]);
    expect(app(OnboardingRequirementRegistry::class)->secondaryOpportunisticFor(2, $pending, $contact))->toBeNull();

    $contact2 = Contact::factory()->create();
    $complete = unsatisfiedOpportunisticProfile($contact2);
    expect(app(OnboardingRequirementRegistry::class)->secondaryOpportunisticFor(2, $complete, $contact2))->toBeNull();
});

it('turn 3 offers the first unsatisfied opportunistic, in registration order, regardless of blocking state', function () {
    $contact = Contact::factory()->create();
    $pending = TrainingProfile::factory()->incomplete()->create(['contact_id' => $contact->id]);

    $secondary = app(OnboardingRequirementRegistry::class)->secondaryOpportunisticFor(3, $pending, $contact);

    expect($secondary->key())->toBe('sessions_per_week');
});

it('turn 4 continues offering an opportunistic invitation, moving to the next unsatisfied one once the prior is satisfied', function () {
    $contact = Contact::factory()->create();
    $profile = unsatisfiedOpportunisticProfile($contact, ['sessions_per_week' => 3]); // ya satisfecho

    $secondary = app(OnboardingRequirementRegistry::class)->secondaryOpportunisticFor(4, $profile, $contact);

    expect($secondary->key())->toBe('primary_focus');
});

it('no invitation is offered from turn 3 onward once every Capa 2 requirement is already satisfied', function () {
    $contact = Contact::factory()->create();
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id]); // defaults: todos satisfechos

    expect(app(OnboardingRequirementRegistry::class)->secondaryOpportunisticFor(5, $profile, $contact))->toBeNull();
});
