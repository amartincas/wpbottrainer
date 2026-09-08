<?php

use App\Filament\Resources\Referral\Pages\ListReferrals;
use App\Filament\Resources\Referral\Pages\ViewReferral;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Referrals\Enums\ReferralRewardApplicationStatus;
use App\Referrals\Models\Referral;
use App\Referrals\Models\ReferralReward;
use Livewire\Livewire;

/**
 * Hito 13 — ReferralResource, deliberadamente de solo lectura. Tenant
 * isolation es el foco principal — el resto ya está probado a nivel de
 * dominio (ReferralAttributionPreRoutingScreenTest,
 * ApplyReferralRewardOnPaymentConfirmedTest).
 */

it('only lists referrals whose referred contact belongs to the admin own tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenantA->id]);

    $referrerA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $referredA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
    $mine = Referral::factory()->create(['referrer_contact_id' => $referrerA->id, 'referred_contact_id' => $referredA->id]);

    $referrerB = Contact::factory()->create(['tenant_id' => $tenantB->id]);
    $referredB = Contact::factory()->create(['tenant_id' => $tenantB->id]);
    $other = Referral::factory()->create(['referrer_contact_id' => $referrerB->id, 'referred_contact_id' => $referredB->id]);

    Livewire::actingAs($admin)
        ->test(ListReferrals::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);
});

it('lets a super admin see referrals across every tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $referralA = Referral::factory()->create([
        'referrer_contact_id' => Contact::factory()->create(['tenant_id' => $tenantA->id])->id,
        'referred_contact_id' => Contact::factory()->create(['tenant_id' => $tenantA->id])->id,
    ]);
    $referralB = Referral::factory()->create([
        'referrer_contact_id' => Contact::factory()->create(['tenant_id' => $tenantB->id])->id,
        'referred_contact_id' => Contact::factory()->create(['tenant_id' => $tenantB->id])->id,
    ]);

    Livewire::actingAs($superAdmin)
        ->test(ListReferrals::class)
        ->assertCanSeeTableRecords([$referralA, $referralB]);
});

it('denies viewing a referral belonging to another tenant, even by direct route', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenantA->id]);

    $other = Referral::factory()->create([
        'referrer_contact_id' => Contact::factory()->create(['tenant_id' => $tenantB->id])->id,
        'referred_contact_id' => Contact::factory()->create(['tenant_id' => $tenantB->id])->id,
    ]);

    Livewire::actingAs($admin)
        ->test(ViewReferral::class, ['record' => $other->getRouteKey()]);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('renders the detail view correctly for each application_status, including a referral with no reward yet', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $noReward = Referral::factory()->create();
    Livewire::actingAs($superAdmin)->test(ViewReferral::class, ['record' => $noReward->getRouteKey()])->assertOk();

    foreach (ReferralRewardApplicationStatus::cases() as $status) {
        $referral = Referral::factory()->create();
        ReferralReward::factory()->create(['referral_id' => $referral->id, 'application_status' => $status]);

        Livewire::actingAs($superAdmin)->test(ViewReferral::class, ['record' => $referral->getRouteKey()])->assertOk();
    }
});

it('filters the table by application_status', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $pendingReferral = Referral::factory()->create();
    ReferralReward::factory()->pending()->create(['referral_id' => $pendingReferral->id]);

    $appliedReferral = Referral::factory()->create();
    ReferralReward::factory()->create(['referral_id' => $appliedReferral->id]); // applied por defecto

    Livewire::actingAs($superAdmin)
        ->test(ListReferrals::class)
        ->filterTable('application_status', ReferralRewardApplicationStatus::Pending->value)
        ->assertCanSeeTableRecords([$pendingReferral])
        ->assertCanNotSeeTableRecords([$appliedReferral]);
});
