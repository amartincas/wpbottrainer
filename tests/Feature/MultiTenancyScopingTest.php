<?php

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Confirms the Filament multi-tenant scoping (store_id -> tenant_id) still
 * isolates data per tenant after the Store->Tenant / Lead->Contact rename.
 */

it('only shows contacts belonging to the authenticated tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    Contact::factory()->create(['tenant_id' => $tenantA->id, 'customer_phone' => '5730001']);
    Contact::factory()->create(['tenant_id' => $tenantB->id, 'customer_phone' => '5730002']);

    $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'is_super_admin' => false]);
    Auth::login($userA);

    $visiblePhones = ContactResource::getEloquentQuery()->pluck('customer_phone')->all();

    expect($visiblePhones)->toBe(['5730001']);
});

it('lets a super admin see contacts from every tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    Contact::factory()->create(['tenant_id' => $tenantA->id, 'customer_phone' => '5730001']);
    Contact::factory()->create(['tenant_id' => $tenantB->id, 'customer_phone' => '5730002']);

    $superAdmin = User::factory()->create(['tenant_id' => $tenantA->id, 'is_super_admin' => true]);
    Auth::login($superAdmin);

    $visiblePhones = ContactResource::getEloquentQuery()->pluck('customer_phone')->sort()->values()->all();

    expect($visiblePhones)->toBe(['5730001', '5730002']);
});

it('only shows products belonging to the authenticated tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    Product::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Producto A']);
    Product::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Producto B']);

    $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'is_super_admin' => false]);
    Auth::login($userA);

    $visibleNames = ProductResource::getEloquentQuery()->pluck('name')->all();

    expect($visibleNames)->toBe(['Producto A']);
});
