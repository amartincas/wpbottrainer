<?php

use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Models\TrainingRestriction;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Training\Enums\BodyRegion;
use App\Training\Enums\HealthConditionCategory;
use App\Training\Enums\HealthConditionStatus;
use App\Training\Enums\RestrictionSource;
use App\Training\Enums\RestrictionStatus;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\DeclaredHealthConditionRecorder;
use App\Training\Support\FunctionalLimitationCanonicalMapper;

function recorder(): DeclaredHealthConditionRecorder
{
    return new DeclaredHealthConditionRecorder(new BodyRegionCanonicalMapper, new FunctionalLimitationCanonicalMapper);
}

// ── A: registro básico + invariante de que declare() nunca crea restricciones ──

it('Case A: "lesión de hombro" suggests shoulder but creates NO TrainingRestriction', function () {
    $contact = Contact::factory()->create();

    // El catálogo (BodyRegionCanonicalMapper) es de correspondencia EXACTA,
    // no de palabra clave — usamos la frase exacta del catálogo, tal como
    // se verificó contra producción en el Bloque 1.
    $condition = recorder()->declare($contact, 'lesión de hombro', HealthConditionCategory::PossibleInjury);

    expect($condition->suggested_body_region)->toBe(BodyRegion::Shoulder);
    expect($condition->status)->toBe(HealthConditionStatus::PendingReview);
    expect(TrainingRestriction::count())->toBe(0);
});

// ── B: incluso con texto reconocido, declare() nunca auto-confirma — eso es responsabilidad del futuro HealthScreeningRequirement ──

it('Case B: declare() never auto-confirms a functional restriction merely because the text maps to a known BodyRegion — that belongs to a future functional-screening flow', function () {
    $contact = Contact::factory()->create();

    // "manguito rotador" SÍ es reconocido por el catálogo (Bloque 1) — y
    // aun así, ninguna restricción debe crearse aquí. Reconocer una
    // CONDICIÓN conocida no es lo mismo que el usuario declarando una
    // LIMITACIÓN FUNCIONAL explícita.
    $condition = recorder()->declare($contact, 'manguito rotador', HealthConditionCategory::PossibleInjury);

    expect($condition->suggested_body_region)->toBe(BodyRegion::Shoulder);
    expect($condition->status)->toBe(HealthConditionStatus::PendingReview);
    expect(TrainingRestriction::count())->toBe(0);
    expect($condition->related_restriction_id)->toBeNull();
});

// ── C: possible_recovery nunca modifica automáticamente una restricción existente ──

it('Case C: a possible_recovery declaration never automatically modifies an existing confirmed TrainingRestriction', function () {
    $contact = Contact::factory()->create();
    $existingRestriction = TrainingRestriction::factory()->create([
        'contact_id' => $contact->id,
        'body_region' => BodyRegion::Shoulder,
        'status' => RestrictionStatus::Confirmed,
    ]);

    recorder()->declare($contact, 'ya estoy recuperado', HealthConditionCategory::PossibleRecovery);

    $existingRestriction->refresh();
    expect($existingRestriction->status)->toBe(RestrictionStatus::Confirmed);
    expect($existingRestriction->body_region)->toBe(BodyRegion::Shoulder);
});

// ── D: professional_indication nunca auto-confirma, aunque el texto sea reconocido ──

it('Case D: a professional_indication declaration never auto-confirms, even with recognized text', function () {
    $contact = Contact::factory()->create();

    $condition = recorder()->declare(
        $contact,
        'hernia discal',
        HealthConditionCategory::ProfessionalIndication,
    );

    expect($condition->suggested_body_region)->toBe(BodyRegion::LowerBack);
    expect($condition->status)->toBe(HealthConditionStatus::PendingReview);
    expect(TrainingRestriction::count())->toBe(0);
});

// ── Registro básico: texto original preservado literal ──

it('preserves the original text verbatim, regardless of whether it is recognized', function () {
    $contact = Contact::factory()->create();
    $text = 'Me duele mucho el hombro derecho cuando levanto los brazos';

    $condition = recorder()->declare($contact, $text, HealthConditionCategory::PossibleInjury);

    expect($condition->original_text)->toBe($text);
    expect($condition->suggested_body_region)->toBeNull(); // no coincide exacto con el catálogo
});

// ── source_message_id se conserva ──

it('preserves source_message_id when a WhatsAppMessage is provided', function () {
    $contact = Contact::factory()->create();
    $message = WhatsAppMessage::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $contact->customer_phone,
        'role' => 'user',
        'content' => 'tengo una lesión en el hombro',
    ]);

    $condition = recorder()->declare(
        $contact,
        'tengo una lesión en el hombro',
        HealthConditionCategory::PossibleInjury,
        $message->id,
    );

    expect($condition->source_message_id)->toBe($message->id);
    expect($condition->sourceMessage->id)->toBe($message->id);
});

it('allows source_message_id to be null when no specific message is identifiable', function () {
    $contact = Contact::factory()->create();

    $condition = recorder()->declare($contact, 'algo que se registró sin mensaje puntual', HealthConditionCategory::PossibleInjury);

    expect($condition->source_message_id)->toBeNull();
});

// ── Integridad: source_message_id debe pertenecer al MISMO contacto (tenant_id + customer_phone) ──
// WhatsAppMessage no tiene contact_id — se identifica solo por tenant_id +
// customer_phone. Una FK simple garantiza que el ID exista, pero NO que
// pertenezca al contacto que está declarando. Esta prueba demuestra que
// esa correspondencia SÍ se valida.

it('refuses to associate a declaration from Contact A with a WhatsAppMessage belonging to Contact B', function () {
    $contactA = Contact::factory()->create();
    $contactB = Contact::factory()->create(); // tenant_id y customer_phone distintos, por diseño de la factory

    $messageOfB = WhatsAppMessage::create([
        'tenant_id' => $contactB->tenant_id,
        'customer_phone' => $contactB->customer_phone,
        'role' => 'user',
        'content' => 'mensaje de otro contacto',
    ]);

    expect(fn () => recorder()->declare(
        $contactA,
        'tengo una lesión en el hombro',
        HealthConditionCategory::PossibleInjury,
        $messageOfB->id,
    ))->toThrow(InvalidArgumentException::class);

    expect(DeclaredHealthCondition::count())->toBe(0);
});

it('refuses a source_message_id that does not exist at all', function () {
    $contact = Contact::factory()->create();

    expect(fn () => recorder()->declare(
        $contact,
        'tengo una lesión en el hombro',
        HealthConditionCategory::PossibleInjury,
        999999,
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects a message from the same tenant but a different customer_phone (tenant match alone is not enough)', function () {
    $contact = Contact::factory()->create();
    $otherContactSameTenant = Contact::factory()->create(['tenant_id' => $contact->tenant_id]);

    $messageOfOther = WhatsAppMessage::create([
        'tenant_id' => $contact->tenant_id,
        'customer_phone' => $otherContactSameTenant->customer_phone,
        'role' => 'user',
        'content' => 'mensaje de otro contacto del mismo tenant',
    ]);

    expect(fn () => recorder()->declare(
        $contact,
        'tengo una lesión en el hombro',
        HealthConditionCategory::PossibleInjury,
        $messageOfOther->id,
    ))->toThrow(InvalidArgumentException::class);
});

// ── resolveWithRestriction(): único camino de creación, source SIEMPRE explícito, nunca derivado de category ──

it('resolveWithRestriction() creates a TrainingRestriction using the EXPLICIT source given, never derived from category', function () {
    $contact = Contact::factory()->create();
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->professionalIndication()->create(['contact_id' => $contact->id]);

    // Deliberadamente pasamos un source distinto al que "category" haría
    // pensar de forma intuitiva — prueba directa de que no hay derivación
    // automática alguna.
    $restriction = recorder()->resolveWithRestriction(
        $condition,
        BodyRegion::Shoulder,
        RestrictionSource::UserVague,
        $reviewer,
        'Revisado manualmente, se confirma como vago pese al origen profesional reportado.',
    );

    expect($restriction->source)->toBe(RestrictionSource::UserVague);
    expect($restriction->status)->toBe(RestrictionStatus::Confirmed);
    expect($restriction->body_region)->toBe(BodyRegion::Shoulder);
});

it('resolveWithRestriction() links both sides of the relationship and marks the condition resolved', function () {
    $contact = Contact::factory()->create();
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);

    $restriction = recorder()->resolveWithRestriction(
        $condition,
        BodyRegion::Knee,
        RestrictionSource::UserExplicit,
        $reviewer,
        'Confirmado tras aclarar con el usuario.',
    );

    $condition->refresh();

    expect($restriction->declared_health_condition_id)->toBe($condition->id);
    expect($condition->related_restriction_id)->toBe($restriction->id);
    expect($condition->status)->toBe(HealthConditionStatus::ResolvedRestrictionCreated);
    expect($condition->reviewed_by)->toBe($reviewer->id);
    expect($condition->reviewed_at)->not->toBeNull();
});

it('resolveWithRestriction() always requires a human reviewer — there is no automatic path to Confirmed in this block', function () {
    $reflection = new ReflectionMethod(DeclaredHealthConditionRecorder::class, 'resolveWithRestriction');
    $reviewerParam = collect($reflection->getParameters())->firstWhere('name', 'reviewer');

    expect($reviewerParam)->not->toBeNull();
    expect($reviewerParam->allowsNull())->toBeFalse();
    expect((string) $reviewerParam->getType())->toBe(User::class);
});

// ── Atomicidad: si la actualización de la condición falla, la restricción NO debe quedar creada ──

it('resolveWithRestriction() is atomic: if updating the condition fails, no TrainingRestriction is left behind and the condition keeps its original state', function () {
    $contact = Contact::factory()->create();
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $realCondition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);

    // Mock de instancia: preserva el estado real del modelo (id, atributos
    // ya persistidos) pero fuerza que su update() falle — simulando
    // cualquier fallo posterior a la creación de la TrainingRestriction
    // (violación de constraint, excepción de la capa de dominio, etc.).
    $failingCondition = Mockery::mock($realCondition)->makePartial();
    $failingCondition->shouldReceive('update')->once()->andThrow(new RuntimeException('simulated failure after restriction creation'));

    expect(fn () => recorder()->resolveWithRestriction(
        $failingCondition,
        BodyRegion::Knee,
        RestrictionSource::UserExplicit,
        $reviewer,
    ))->toThrow(RuntimeException::class);

    // Ninguna TrainingRestriction debe sobrevivir a la transacción revertida.
    expect(TrainingRestriction::count())->toBe(0);

    // La condición real (leída de nuevo desde la BD) no debe mostrar
    // ningún enlace unidireccional ni cambio de estado.
    $realCondition->refresh();
    expect($realCondition->status)->toBe(HealthConditionStatus::PendingReview);
    expect($realCondition->related_restriction_id)->toBeNull();
    expect($realCondition->reviewed_by)->toBeNull();
    expect($realCondition->reviewed_at)->toBeNull();
});

// ── resolveWithoutRestriction() ──

it('resolveWithoutRestriction() marks the condition resolved without creating anything', function () {
    $contact = Contact::factory()->create();
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);

    recorder()->resolveWithoutRestriction($condition, $reviewer, 'No amerita restricción funcional.');

    $condition->refresh();
    expect($condition->status)->toBe(HealthConditionStatus::ResolvedNoRestriction);
    expect($condition->related_restriction_id)->toBeNull();
    expect(TrainingRestriction::count())->toBe(0);
});

// ── supersede() ──

it('supersede() marks a condition as superseded, always via an explicit human action', function () {
    $contact = Contact::factory()->create();
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $condition = DeclaredHealthCondition::factory()->create(['contact_id' => $contact->id]);

    recorder()->supersede($condition, $reviewer);

    $condition->refresh();
    expect($condition->status)->toBe(HealthConditionStatus::Superseded);
    expect($condition->reviewed_by)->toBe($reviewer->id);
});
