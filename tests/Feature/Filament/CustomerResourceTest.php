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
