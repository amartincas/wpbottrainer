<?php

use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

/**
 * Hito 13 — configuración del programa de Referidos administrable en
 * Filament. `EditTenant` (no `TenantResource::form()`/`TenantForm`) es la
 * página real que un administrador usa para modificar un Tenant ya
 * existente — sobreescribe `form()` para usar `TenantWizardForm`, así que
 * es ahí donde deben vivir estos campos para ser realmente alcanzables.
 */

it('saves the referral configuration fields from the real Tenant edit page', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $tenant = Tenant::factory()->create([
        'wa_display_phone_number' => null,
        'referral_reward_days' => 3,
        'referral_program_enabled' => true,
    ]);

    Livewire::actingAs($superAdmin)
        ->test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm([
            'wa_display_phone_number' => '573001234567',
            'referral_reward_days' => 7,
            'referral_program_enabled' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $tenant->fresh();
    expect($fresh->wa_display_phone_number)->toBe('573001234567');
    expect($fresh->referral_reward_days)->toBe(7);
    expect($fresh->referral_program_enabled)->toBeFalse();
});
