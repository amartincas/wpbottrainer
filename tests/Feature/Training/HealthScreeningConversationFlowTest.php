<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
use App\Training\Onboarding\OnboardingRequirementRegistry;
use Illuminate\Support\Facades\Http;

/**
 * Bloque 5 (D048) — cobertura E2E vía el Job real, mismo patrón de
 * TrainingConversationFlowTest.php. Reutiliza fakeOnboardingTurn()/
 * emptyExtraction()/sendTrainingMessage() ya declaradas allí (funciones
 * globales de Pest, no se redeclaran aquí).
 */
function readyProfileForScreening(Contact $contact, array $overrides = []): TrainingProfile
{
    return TrainingProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'goal' => TrainingGoal::BuildMuscle,
        'experience_level' => ExperienceLevel::Beginner,
        'training_location' => TrainingLocation::Home,
        'available_equipment' => [],
    ], $overrides));
}

it('completes the initial + follow-up screening conversation, then blocks the first routine on health_screening_pending', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    readyProfileForScreening($contact);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(fakeOnboardingTurn(
                emptyExtraction(['health_condition_text' => 'tengo una lesión de hombro']),
                'ask_health_screening',
                '¿Hay algún movimiento específico que te cause molestia o debas evitar?'
            ))
            ->push(fakeOnboardingTurn(
                emptyExtraction(['health_condition_text' => 'no sé, simplemente me duele']),
                'ask_health_screening',
                'Entendido, gracias por contarme.'
            )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', 'Tengo una lesión de hombro');
    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile->health_screening_asked)->toBeFalse();
    expect(WorkoutSession::count())->toBe(0);

    sendTrainingMessage($tenant, '573001112233', 'No sé, simplemente me duele');
    $profile->refresh();

    // Screening conversacional cerrado (onboarding bloqueante completo)...
    expect($profile->health_screening_asked)->toBeTrue();
    expect(app(OnboardingRequirementRegistry::class)->isOnboardingComplete($profile, $contact->fresh()))->toBeTrue();
    // ...pero la primera rutina sigue bloqueada por revisión humana pendiente.
    expect(WorkoutSession::count())->toBe(0);
    expect(DeclaredHealthCondition::count())->toBe(2); // dos hechos, nunca una edición
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'revisar la información'));
});

it('19: once resolved without a restriction, the first routine generates exactly once even if the user writes again', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    readyProfileForScreening($contact, ['health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    DeclaredHealthCondition::factory()->resolvedNoRestriction()->create(['contact_id' => $contact->id]);
    Exercise::factory()->create(['muscle_group' => 'chest']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    sendTrainingMessage($tenant, '573001112233', 'Dame mi entrenamiento');
    expect(WorkoutSession::count())->toBe(1);

    sendTrainingMessage($tenant, '573001112233', 'hola de nuevo');
    expect(WorkoutSession::count())->toBe(1); // idempotencia ya garantizada por TrainingEngine
});

it('L: SafetySignalDetector keeps precedence over health screening extraction', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    sendTrainingMessage($tenant, '573001112233', 'Quiero entrenar pero tengo un fuerte dolor de pecho');

    expect(TrainingProfile::where('contact_id', $contact->id)->first()->isFlaggedForSafetyReview())->toBeTrue();
    expect(DeclaredHealthCondition::count())->toBe(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
});

it('N: the visible health screening question comes from the AI response when it is usable', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    readyProfileForScreening($contact);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(fakeOnboardingTurn(
            emptyExtraction(),
            'ask_health_screening',
            'Como vas a entrenar en casa, quiero asegurarme de adaptar bien los ejercicios. ¿Tienes alguna lesión, dolor o molestia que deba tener en cuenta?'
        ), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', 'listo');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'entrenar en casa'));
});

it('O: the deterministic fallback is used when the AI next_action does not match the real pending requirement', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    readyProfileForScreening($contact);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        // La IA cree que falta el objetivo — el sistema ya sabe que en
        // realidad falta el screening de salud, así que se ignora.
        'api.openai.com/v1/chat/completions' => Http::response(fakeOnboardingTurn(emptyExtraction(), 'ask_goal', '¿Cuál es tu objetivo?'), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', 'listo');

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'Antes de comenzar'));
});

it('Q: a compound reply resolves health screening together with another pending requirement in the same message', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'customer_name' => 'Ana']);
    readyProfileForScreening($contact, ['available_equipment' => null]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(fakeOnboardingTurn(
            emptyExtraction(['available_equipment' => [], 'health_condition_text' => '']),
            'complete_onboarding',
            '¡Perfecto!'
        ), 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    sendTrainingMessage($tenant, '573001112233', 'No tengo equipo y tampoco ninguna lesión');

    $profile = TrainingProfile::where('contact_id', $contact->id)->first();
    expect($profile->available_equipment)->toBe([]);
    expect($profile->health_screening_asked)->toBeTrue();
    expect(app(OnboardingRequirementRegistry::class)->isOnboardingComplete($profile, $contact->fresh()))->toBeTrue();
    expect(DeclaredHealthCondition::count())->toBe(0);
});
