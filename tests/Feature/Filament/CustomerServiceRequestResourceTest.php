<?php

use App\CustomerCare\Models\CustomerServiceRequest;
use App\Filament\Resources\CustomerServiceRequest\Pages\ListCustomerServiceRequests;
use App\Filament\Resources\CustomerServiceRequest\Pages\ViewCustomerServiceRequest;
use App\Filament\Resources\CustomerServiceRequestResource;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

/**
 * Hito 14 — CustomerServiceRequestResource. Deliberadamente de solo lectura
 * — registro histórico append-only de un hecho ya ocurrido (ver docblock de
 * la clase). Tenant isolation vía `contact.tenant_id` (el modelo no tiene
 * `tenant_id` propio) es el foco principal.
 */
it('only lists requests whose contact belongs to the admin own tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenantA->id]);

    $mine = CustomerServiceRequest::factory()->create(['contact_id' => Contact::factory()->create(['tenant_id' => $tenantA->id])->id]);
    $other = CustomerServiceRequest::factory()->create(['contact_id' => Contact::factory()->create(['tenant_id' => $tenantB->id])->id]);

    Livewire::actingAs($admin)
        ->test(ListCustomerServiceRequests::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);
});

it('lets a super admin see requests across every tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $requestA = CustomerServiceRequest::factory()->create(['contact_id' => Contact::factory()->create(['tenant_id' => $tenantA->id])->id]);
    $requestB = CustomerServiceRequest::factory()->create(['contact_id' => Contact::factory()->create(['tenant_id' => $tenantB->id])->id]);

    Livewire::actingAs($superAdmin)
        ->test(ListCustomerServiceRequests::class)
        ->assertCanSeeTableRecords([$requestA, $requestB]);
});

it('denies viewing a request belonging to another tenant, even by direct route', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenantA->id]);
    $other = CustomerServiceRequest::factory()->create(['contact_id' => Contact::factory()->create(['tenant_id' => $tenantB->id])->id]);

    Livewire::actingAs($admin)
        ->test(ViewCustomerServiceRequest::class, ['record' => $other->getRouteKey()]);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('renders the detail view correctly for a real request', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $request = CustomerServiceRequest::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test(ViewCustomerServiceRequest::class, ['record' => $request->getRouteKey()])
        ->assertOk();
});

it('is entirely read-only: no create route, and only ViewAction is registered (no EditAction/DeleteAction)', function () {
    expect(CustomerServiceRequestResource::getPages())->not->toHaveKey('create');
    expect(CustomerServiceRequestResource::getPages())->not->toHaveKey('edit');

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $request = CustomerServiceRequest::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test(ListCustomerServiceRequests::class)
        ->assertTableActionExists('view', record: $request)
        ->assertTableActionDoesNotExist('edit', record: $request)
        ->assertTableActionDoesNotExist('delete', record: $request);
});
