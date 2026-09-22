<?php

use App\Filament\Resources\Customer\Pages\ListCustomers;
use App\Filament\Resources\Customer\Pages\ViewCustomer;
use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingAccessAudit;
use App\Models\TrainingProfile;
use App\Models\TrainingRestriction;
use App\Models\User;
use App\Payments\Enums\PaymentStatus;
use App\Training\Enums\SafetyStatus;
use App\Training\Enums\TrainingAccessStatus;
use Livewire\Livewire;

/**
 * Hito 12 — CustomerResource. No depende exclusivamente de tests visuales:
 * cada acción administrativa se prueba verificando el efecto real sobre
 * TrainingAccess/TrainingAccessAudit (la regla de negocio ya está probada
 * en `TrainingAccessAdministrationServiceTest`; aquí se prueba el cableado
 * de Filament — visibilidad, autorización, aislamiento por tenant).
 */
it('only lists customers belonging to the admin own tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenantA->id]);
    $mine = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $other = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    Livewire::actingAs($admin)
        ->test(ListCustomers::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);
});

it('lets a super admin see customers across every tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    Livewire::actingAs($superAdmin)
        ->test(ListCustomers::class)
        ->assertCanSeeTableRecords([$contactA, $contactB]);
});

it('denies viewing a customer belonging to another tenant, even by direct route', function () {
    // getEloquentQuery() ya excluye la fila por completo para este admin
    // (mismo mecanismo que el resto del panel, sin tenancy nativa de
    // Filament) — Livewire nunca llega a montar la página, resuelve como
    // "no existe", no como un 403 explícito.
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenantA->id]);
    $other = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    Livewire::actingAs($admin)
        ->test(ViewCustomer::class, ['record' => $other->getRouteKey()]);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

// ── Estado de acceso mostrado (efectivo, nunca crudo) ───────────────────

it('shows the effective status, not the raw one, when a time-bound access is already expired', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->for($contact)->create([
        'status' => TrainingAccessStatus::Active,
        'expires_at' => now()->subDay(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListCustomers::class)
        ->assertTableColumnStateSet('access_status', TrainingAccessStatus::Expired, $contact);
});

// ── Autorización: las 5 acciones solo existen para is_super_admin ───────

it('hides every one of the 5 administrative actions from a non-super-admin', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenant->id]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingAccess::factory()->for($contact)->revoked()->create();

    Livewire::actingAs($admin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->assertActionHidden('grant_trial')
        ->assertActionHidden('grant_free')
        ->assertActionHidden('extend')
        ->assertActionHidden('revoke')
        ->assertActionHidden('reactivate');
});

it('shows grant_trial and grant_free to a super admin regardless of current access state', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->assertActionVisible('grant_trial')
        ->assertActionVisible('grant_free');
});

it('hides extend and revoke once the access is already revoked, and shows reactivate only then', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->for($contact)->revoked()->create();

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->assertActionHidden('extend')
        ->assertActionHidden('revoke')
        ->assertActionVisible('reactivate');
});

it('shows extend and revoke for an active access, and hides reactivate', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->for($contact)->create(['status' => TrainingAccessStatus::Active]);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->assertActionVisible('extend')
        ->assertActionVisible('revoke')
        ->assertActionHidden('reactivate');
});

// ── Comportamiento real de cada acción (delegación al servicio) ─────────

it('grant_trial creates a Trial TrainingAccess and exactly one audit row, never a Payment', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    expect(Payment::count())->toBe(0);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->callAction('grant_trial', data: ['duration_days' => 7, 'reason' => 'Cortesía'])
        ->assertHasNoActionErrors();

    $access = $contact->fresh()->trainingAccess;
    expect($access->status)->toBe(TrainingAccessStatus::Trial);
    expect($access->expires_at->toDateString())->toBe(now()->addDays(7)->toDateString());
    expect(TrainingAccessAudit::where('training_access_id', $access->id)->count())->toBe(1);
    expect(Payment::count())->toBe(0);
});

it('grant_free creates a Free TrainingAccess without ever creating a Payment', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->callAction('grant_free', data: ['until' => null, 'reason' => 'Embajador'])
        ->assertHasNoActionErrors();

    $access = $contact->fresh()->trainingAccess;
    expect($access->status)->toBe(TrainingAccessStatus::Free);
    expect($access->expires_at)->toBeNull();
    expect(Payment::count())->toBe(0);
});

it('extend pushes expires_at forward without ever changing status or creating a Payment', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    $access = TrainingAccess::factory()->for($contact)->create([
        'status' => TrainingAccessStatus::Active,
        'expires_at' => now()->addDays(5),
    ]);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->callAction('extend', data: ['months' => 1, 'reason' => null])
        ->assertHasNoActionErrors();

    $fresh = $access->fresh();
    expect($fresh->status)->toBe(TrainingAccessStatus::Active);
    expect($fresh->expires_at->toDateString())->toBe(now()->addDays(5)->addMonth()->toDateString());
    expect(Payment::count())->toBe(0);
});

it('revoke requires a reason and rejects an empty one without persisting anything', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    $access = TrainingAccess::factory()->for($contact)->create(['status' => TrainingAccessStatus::Active]);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->callAction('revoke', data: ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect($access->fresh()->status)->toBe(TrainingAccessStatus::Active);
});

it('revoke sets status to Revoked, preserves expires_at, and records an audit row', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    $expiresAt = now()->addDays(10);
    $access = TrainingAccess::factory()->for($contact)->create([
        'status' => TrainingAccessStatus::Active,
        'expires_at' => $expiresAt,
    ]);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->callAction('revoke', data: ['reason' => 'Fraude confirmado'])
        ->assertHasNoActionErrors();

    $fresh = $access->fresh();
    expect($fresh->status)->toBe(TrainingAccessStatus::Revoked);
    expect($fresh->expires_at->toDateTimeString())->toBe($expiresAt->toDateTimeString());
    expect(TrainingAccessAudit::where('training_access_id', $access->id)->where('action', 'revoked')->count())->toBe(1);
});

it('reactivate never offers Active as a selectable target status (grep proof on the Select options)', function () {
    // La regla real vive en el servicio (probada en
    // TrainingAccessAdministrationServiceTest); aquí se prueba que
    // Filament ni siquiera ofrece la opción — coherente con "Active solo
    // proviene de un Payment confirmado".
    $contents = file_get_contents(app_path('Filament/Resources/Customer/Pages/ViewCustomer.php'));

    expect($contents)->toContain("TrainingAccessStatus::Trial->value => 'Trial'");
    expect($contents)->toContain("TrainingAccessStatus::Free->value => 'Free'");
    expect($contents)->not->toContain("TrainingAccessStatus::Active->value => 'Active'");
});

it('reactivate requires an explicit expires_at when reactivating to Trial', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->for($contact)->revoked()->create();

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->callAction('reactivate', data: ['target_status' => 'trial', 'until' => null, 'reason' => null])
        ->assertHasActionErrors(['until']);
});

it('reactivate to Free never restores the previous expires_at automatically', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    $access = TrainingAccess::factory()->for($contact)->create([
        'status' => TrainingAccessStatus::Revoked,
        'expires_at' => now()->addMonths(3), // fecha "vieja" previa a revocar
    ]);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->callAction('reactivate', data: ['target_status' => 'free', 'until' => null, 'reason' => 'Reactivación manual'])
        ->assertHasNoActionErrors();

    $fresh = $access->fresh();
    expect($fresh->status)->toBe(TrainingAccessStatus::Free);
    expect($fresh->expires_at)->toBeNull();
});

// ── Renderizado del Infolist con datos reales en las 6 secciones ────────
// Regresión: un hallazgo real en staging (customers/{id} -> 500) mostró que
// ningún test anterior ejercitaba ViewCustomer para un Contact con
// TrainingProfile/salud/restricciones/pagos REALES — todas las relaciones
// venían vacías, así que un closure con un type-hint incorrecto (?string en
// vez del enum real que el modelo castea) nunca se ejecutaba con un valor
// no-null y el error quedaba invisible. Estos tests fuerzan cada sección a
// tener datos reales.

it('renders the full customer detail page for a contact with a complete TrainingProfile (safety_status Normal)', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->for($contact)->create(['safety_status' => SafetyStatus::Normal]);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->assertOk();
});

it('renders the full customer detail page when safety_status is FlaggedForReview, the other real enum case', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->for($contact)->create(['safety_status' => SafetyStatus::FlaggedForReview]);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->assertOk();
});

it('renders the full customer detail page with real health conditions, restrictions, payments, and audit history all populated at once', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    TrainingProfile::factory()->for($contact)->create();
    DeclaredHealthCondition::factory()->for($contact)->create();
    TrainingRestriction::factory()->for($contact)->create();
    $payment = Payment::factory()->for($contact)->create(['status' => PaymentStatus::Confirmed]);
    $access = TrainingAccess::factory()->for($contact)->create(['status' => TrainingAccessStatus::Active, 'payment_id' => $payment->id]);
    app(\App\Training\Support\TrainingAccessAdministrationService::class)->extend($contact, $superAdmin, 1, 'para poblar el historial de auditoría');

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->assertOk();
});

it('renders the customers list and detail page for a contact with no related data at all, without failing', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test(ListCustomers::class)
        ->assertOk();

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->assertOk();
});

// ── Hito A (Safety Administration) — confirm_health_restriction /
// resolve_health_condition_without_restriction, delegando exactamente en
// App\Training\Support\DeclaredHealthConditionRecorder (sin cambios) ────

it('hides both safety actions when the Contact has no pending_review DeclaredHealthCondition', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->assertActionHidden('confirm_health_restriction')
        ->assertActionHidden('resolve_health_condition_without_restriction');
});

it('hides both safety actions from a non-super-admin even with a pending condition', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenant->id]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    DeclaredHealthCondition::factory()->for($contact)->create(['status' => \App\Training\Enums\HealthConditionStatus::PendingReview]);

    Livewire::actingAs($admin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->assertActionHidden('confirm_health_restriction')
        ->assertActionHidden('resolve_health_condition_without_restriction');
});

it('shows both safety actions to a super admin when a pending_review DeclaredHealthCondition exists', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    DeclaredHealthCondition::factory()->for($contact)->create(['status' => \App\Training\Enums\HealthConditionStatus::PendingReview]);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->assertActionVisible('confirm_health_restriction')
        ->assertActionVisible('resolve_health_condition_without_restriction');
});

it('confirm_health_restriction creates a confirmed TrainingRestriction and resolves the declaration, with the acting admin as reviewer', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    $condition = DeclaredHealthCondition::factory()->for($contact)->create([
        'status' => \App\Training\Enums\HealthConditionStatus::PendingReview,
        'original_text' => 'no puedo cargar peso en el hombro',
    ]);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->callAction('confirm_health_restriction', data: [
            'declared_health_condition_id' => $condition->id,
            'body_region' => \App\Training\Enums\BodyRegion::Shoulder->value,
            'source' => \App\Training\Enums\RestrictionSource::UserExplicit->value,
            'note' => 'Confirmado tras revisión',
        ])
        ->assertHasNoActionErrors();

    $restriction = TrainingRestriction::where('contact_id', $contact->id)->sole();
    expect($restriction->status)->toBe(\App\Training\Enums\RestrictionStatus::Confirmed);
    expect($restriction->body_region)->toBe(\App\Training\Enums\BodyRegion::Shoulder);
    expect($restriction->source)->toBe(\App\Training\Enums\RestrictionSource::UserExplicit);
    expect($restriction->reviewed_by)->toBe($superAdmin->id);

    $freshCondition = $condition->fresh();
    expect($freshCondition->status)->toBe(\App\Training\Enums\HealthConditionStatus::ResolvedRestrictionCreated);
    expect($freshCondition->reviewed_by)->toBe($superAdmin->id);
    expect($freshCondition->related_restriction_id)->toBe($restriction->id);
});

it('resolve_health_condition_without_restriction resolves the declaration without ever creating a TrainingRestriction', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    $condition = DeclaredHealthCondition::factory()->for($contact)->create([
        'status' => \App\Training\Enums\HealthConditionStatus::PendingReview,
    ]);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->callAction('resolve_health_condition_without_restriction', data: [
            'declared_health_condition_id' => $condition->id,
            'note' => 'No corresponde ninguna restricción real',
        ])
        ->assertHasNoActionErrors();

    expect(TrainingRestriction::where('contact_id', $contact->id)->count())->toBe(0);

    $freshCondition = $condition->fresh();
    expect($freshCondition->status)->toBe(\App\Training\Enums\HealthConditionStatus::ResolvedNoRestriction);
    expect($freshCondition->reviewed_by)->toBe($superAdmin->id);
    expect($freshCondition->review_note)->toBe('No corresponde ninguna restricción real');
});

it('resolve_health_condition_without_restriction requires a non-empty note', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $contact = Contact::factory()->create();
    $condition = DeclaredHealthCondition::factory()->for($contact)->create(['status' => \App\Training\Enums\HealthConditionStatus::PendingReview]);

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomer::class, ['record' => $contact->getRouteKey()])
        ->callAction('resolve_health_condition_without_restriction', data: [
            'declared_health_condition_id' => $condition->id,
            'note' => '',
        ])
        ->assertHasActionErrors(['note']);

    expect($condition->fresh()->status)->toBe(\App\Training\Enums\HealthConditionStatus::PendingReview);
});
