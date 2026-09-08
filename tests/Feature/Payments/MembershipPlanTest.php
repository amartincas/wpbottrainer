<?php

use App\Models\Tenant;
use App\Payments\Models\MembershipPlan;

/**
 * Hito 11 — catálogo pequeño, tenant-scoped, de compra única. NO es
 * Subscription: sin ciclo de facturación, sin proration, sin invoices.
 */

it('belongs to a tenant', function () {
    $tenant = Tenant::factory()->create();
    $plan = MembershipPlan::factory()->for($tenant, 'tenant')->create();

    expect($plan->tenant->id)->toBe($tenant->id);
});

it('casts fields correctly', function () {
    $plan = MembershipPlan::factory()->create(['duration_months' => 6, 'price' => 250000, 'is_active' => true]);

    expect($plan->duration_months)->toBeInt();
    expect($plan->duration_months)->toBe(6);
    expect((float) $plan->price)->toBe(250000.0);
    expect($plan->is_active)->toBeTrue();
});

it('duration_months is an open integer — not restricted to 1/3/6/12', function () {
    $plan = MembershipPlan::factory()->create(['duration_months' => 24]);

    expect($plan->duration_months)->toBe(24);
});

it('tenant isolation: a plan created for one tenant never appears when querying another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    MembershipPlan::factory()->for($tenantA, 'tenant')->create(['label' => 'Solo de A']);

    $plansOfB = MembershipPlan::where('tenant_id', $tenantB->id)->get();

    expect($plansOfB->pluck('label'))->not->toContain('Solo de A');
});

it('an inactive plan can be queried but is excluded by the is_active filter PaymentHandler uses for selection', function () {
    $tenant = Tenant::factory()->create();
    $inactive = MembershipPlan::factory()->for($tenant, 'tenant')->inactive()->create();

    $selectable = MembershipPlan::where('tenant_id', $tenant->id)->where('is_active', true)->get();

    expect($selectable->pluck('id'))->not->toContain($inactive->id);
});

it('deactivating or changing a plan never modifies a Payment that already referenced it', function () {
    $tenant = Tenant::factory()->create();
    $plan = MembershipPlan::factory()->for($tenant, 'tenant')->months(3)->create(['price' => 120000]);
    $payment = \App\Models\Payment::factory()->create([
        'membership_plan_id' => $plan->id, 'membership_months' => 3, 'amount' => 120000,
    ]);

    $plan->update(['price' => 999999, 'duration_months' => 99, 'is_active' => false]);

    $fresh = $payment->fresh();
    expect((float) $fresh->amount)->toBe(120000.0);
    expect($fresh->membership_months)->toBe(3);
});

it('deleting a plan does not invalidate a Payment that already referenced it (nullOnDelete)', function () {
    $tenant = Tenant::factory()->create();
    $plan = MembershipPlan::factory()->for($tenant, 'tenant')->months(3)->create(['price' => 120000]);
    $payment = \App\Models\Payment::factory()->create([
        'membership_plan_id' => $plan->id, 'membership_months' => 3, 'amount' => 120000,
    ]);

    $plan->delete();

    $fresh = $payment->fresh();
    expect($fresh->membership_plan_id)->toBeNull(); // procedencia perdida, correcto
    expect((float) $fresh->amount)->toBe(120000.0); // el snapshot histórico sobrevive
    expect($fresh->membership_months)->toBe(3);
});
