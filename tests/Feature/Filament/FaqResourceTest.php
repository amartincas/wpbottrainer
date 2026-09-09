<?php

use App\CustomerCare\Models\Faq;
use App\Filament\Resources\Faq\Pages\CreateFaq;
use App\Filament\Resources\Faq\Pages\EditFaq;
use App\Filament\Resources\Faq\Pages\ListFaqs;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

/**
 * Hito 14 — FaqResource. CRUD completo, tenant-scoped, cualquier admin
 * autenticado (no exclusivo de superadmin) — mismo criterio que
 * MembershipPlanResource. Tenant isolation es el foco principal; el
 * contrato de "la IA nunca inventa" ya está probado a nivel de dominio
 * (FaqMatcherTest, CustomerCareConversationFlowTest).
 */
it('only lists FAQs belonging to the admin own tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenantA->id]);
    $mine = Faq::factory()->create(['tenant_id' => $tenantA->id]);
    $other = Faq::factory()->create(['tenant_id' => $tenantB->id]);

    Livewire::actingAs($admin)
        ->test(ListFaqs::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);
});

it('lets a super admin see FAQs across every tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $faqA = Faq::factory()->create(['tenant_id' => $tenantA->id]);
    $faqB = Faq::factory()->create(['tenant_id' => $tenantB->id]);

    Livewire::actingAs($superAdmin)
        ->test(ListFaqs::class)
        ->assertCanSeeTableRecords([$faqA, $faqB]);
});

it('denies editing a FAQ belonging to another tenant, even by direct route', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenantA->id]);
    $other = Faq::factory()->create(['tenant_id' => $tenantB->id]);

    Livewire::actingAs($admin)
        ->test(EditFaq::class, ['record' => $other->getRouteKey()]);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('a regular (non-super-admin) admin CAN create a FAQ for their own tenant — content management, not a sensitive operation', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenant->id]);

    Livewire::actingAs($admin)
        ->test(CreateFaq::class)
        ->fillForm([
            'tenant_id' => $tenant->id,
            'question' => '¿Cuál es el horario?',
            'answer' => 'Abrimos de 6am a 9pm.',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $faq = Faq::where('tenant_id', $tenant->id)->sole();
    expect($faq->question)->toBe('¿Cuál es el horario?');
    expect($faq->answer)->toBe('Abrimos de 6am a 9pm.');
});

it('a regular admin can edit and deactivate a FAQ belonging to their own tenant', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenant->id]);
    $faq = Faq::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(EditFaq::class, ['record' => $faq->getRouteKey()])
        ->fillForm(['answer' => 'Respuesta actualizada.', 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $faq->fresh();
    expect($fresh->answer)->toBe('Respuesta actualizada.');
    expect($fresh->is_active)->toBeFalse();
});

it('never returns an inactive FAQ as a match candidate once deactivated via Filament (wiring check, not FaqMatcher logic itself)', function () {
    $tenant = Tenant::factory()->create();
    $faq = Faq::factory()->create(['tenant_id' => $tenant->id, 'question' => '¿Cuál es el horario?', 'answer' => 'Respuesta.', 'is_active' => true]);
    $admin = User::factory()->create(['is_super_admin' => false, 'tenant_id' => $tenant->id]);

    Livewire::actingAs($admin)
        ->test(EditFaq::class, ['record' => $faq->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $candidates = app(\App\CustomerCare\Support\FaqMatcher::class)->retrieveCandidates($tenant, '¿cuál es el horario?');
    expect($candidates)->toBeEmpty();
});
