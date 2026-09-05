<?php

use App\Core\Alerts\AlertService;
use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\User;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
use App\Training\Onboarding\Requirements\HealthScreeningRequirement;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\DeclaredHealthConditionRecorder;
use App\Training\Support\FunctionalLimitationCanonicalMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Bloque 5 (D048) — verificación final pedida antes del commit: la alerta
 * al Super Admin se emite exactamente cuando corresponde, nunca de más ni
 * duplicada por un reintento del mismo WAMID.
 *
 * Deliberadamente autocontenido (helpers con nombres propios, sin
 * redeclarar `healthScreeningRequirement()`/`emptyExtraction()`/
 * `realMetaTextPayload()` ya definidas en otros archivos de test) para que
 * este archivo pueda ejecutarse tanto de forma aislada como dentro de la
 * suite completa sin colisión de funciones globales de Pest.
 */
function buildHealthScreeningRequirementForAlertTest(): HealthScreeningRequirement
{
    return new HealthScreeningRequirement(
        new DeclaredHealthConditionRecorder(new BodyRegionCanonicalMapper, new FunctionalLimitationCanonicalMapper),
        app(AlertService::class),
    );
}

function alertTestScreeningValues(array $overrides = []): array
{
    return array_merge([
        'health_declaration_category' => null,
        'health_condition_text' => null,
        'functional_limitation_text' => null,
    ], $overrides);
}

beforeEach(function () {
    Cache::flush();
});

it('a screening answer that creates no DeclaredHealthCondition never triggers an alert', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    $contact = Contact::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => true, 'phone' => '573009998888']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    buildHealthScreeningRequirementForAlertTest()->apply($contact, $profile, alertTestScreeningValues(['health_condition_text' => '']));

    expect(DeclaredHealthCondition::count())->toBe(0);
    Http::assertNotSent(fn ($request) => data_get($request->data(), 'to') === $admin->phone);
});

it('a screening turn that does not answer the question yet (still null) never triggers an alert either', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    $contact = Contact::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => true, 'phone' => '573009998888']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    buildHealthScreeningRequirementForAlertTest()->apply($contact, $profile, alertTestScreeningValues());

    expect(DeclaredHealthCondition::count())->toBe(0);
    Http::assertNotSent(fn ($request) => data_get($request->data(), 'to') === $admin->phone);
});

it('a new declaration triggers exactly one alert to the admin', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    $contact = Contact::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => true, 'phone' => '573009998888']);
    $profile = TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    buildHealthScreeningRequirementForAlertTest()->apply($contact, $profile, alertTestScreeningValues([
        'health_condition_text' => 'tengo una lesión de hombro',
    ]));

    expect(DeclaredHealthCondition::count())->toBe(1);

    $alertsToAdmin = collect(Http::recorded())
        ->filter(fn ($pair) => data_get($pair[0]->data(), 'to') === $admin->phone)
        ->count();

    expect($alertsToAdmin)->toBe(1);
});

it('retrying the same WAMID never creates a second DeclaredHealthCondition nor a second alert', function () {
    $tenant = Tenant::factory()->create(['wa_phone_number_id' => '100000000000099']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'goal' => TrainingGoal::BuildMuscle,
        'experience_level' => ExperienceLevel::Beginner,
        'training_location' => TrainingLocation::Home,
        'available_equipment' => [],
    ]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);
    $admin = User::factory()->create(['is_super_admin' => true, 'phone' => '573009998888']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'extracted' => array_merge([
                'name' => null, 'goal' => null, 'experience_level' => null,
                'primary_focus' => null, 'secondary_focus' => null, 'training_location' => null,
                'restrictions' => null, 'available_equipment' => null, 'equipment_fully_equipped' => null,
                'sessions_per_week' => null, 'age' => null, 'sex' => null, 'weight_kg' => null,
                'height_cm' => null, 'safety_signal_text' => null,
                'health_declaration_category' => null, 'functional_limitation_text' => null,
            ], ['health_condition_text' => 'tengo una lesión de hombro']),
            'next_action' => 'ask_health_screening',
            'response' => '¿Hay algún movimiento específico que te cause molestia o debas evitar?',
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => '100000000000099'],
                    'messages' => [[
                        'id' => 'wamid.HEALTHSCREEN.RETRY1',
                        'from' => '573001112233',
                        'type' => 'text',
                        'text' => ['body' => 'Tengo una lesión de hombro'],
                    ]],
                ],
            ]],
        ]],
    ];

    // Meta reintenta la misma entrega (mismo WAMID) — mismo escenario ya
    // probado en tests/Feature/MetaWebhookTrainingE2ETest.php para reportes
    // de ejecución, ahora verificado también para Health Screening.
    $this->postJson('/api/whatsapp/webhook/anything', $payload)->assertOk();
    $this->postJson('/api/whatsapp/webhook/anything', $payload)->assertOk();

    expect(DeclaredHealthCondition::count())->toBe(1);

    $aiCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.openai.com'))->count();
    expect($aiCalls)->toBe(1); // el reintento nunca vuelve a llamar a la IA

    $alertsToAdmin = collect(Http::recorded())
        ->filter(fn ($pair) => data_get($pair[0]->data(), 'to') === $admin->phone)
        ->count();

    expect($alertsToAdmin)->toBe(1);
});
