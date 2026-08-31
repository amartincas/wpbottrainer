<?php

use App\Filament\Pages\Auth\CustomRegister;
use App\Models\Tenant;
use App\Models\User;

/**
 * Confirms CustomRegister (the real Filament registration flow) still works
 * after Store->Tenant, and that RegisterUser (the orphaned duplicate) being
 * removed did not affect it.
 */

it('renders the Filament registration page', function () {
    $this->get('/register')->assertOk();
});

it('creates a Tenant and a User when a new account registers', function () {
    $page = new CustomRegister();

    $method = new ReflectionMethod(CustomRegister::class, 'handleRegistration');
    $method->setAccessible(true);

    $user = $method->invoke($page, [
        'name' => 'Ana Test',
        'email' => 'ana@example.com',
        'tenant_name' => 'Negocio de Ana',
        'password' => 'password-123',
    ]);

    expect($user)->toBeInstanceOf(User::class);
    expect($user->tenant_id)->not->toBeNull();
    expect($user->is_super_admin)->toBeFalse();

    $tenant = Tenant::find($user->tenant_id);
    expect($tenant)->not->toBeNull();
    expect($tenant->name)->toBe('Negocio de Ana');
    expect($tenant->ai_provider)->toBe('openai');
});
