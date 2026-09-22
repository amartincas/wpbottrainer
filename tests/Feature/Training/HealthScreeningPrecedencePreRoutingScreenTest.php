<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Models\Tenant;
use App\Models\TrainingProfile;
use App\Training\Support\HealthScreeningPrecedencePreRoutingScreen;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Hito A (Safety Precedence, hallazgo de la prueba E2E real) —
 * CustomerServiceEscalationIntentClassifier (Tier 0 del Router) corre
 * SIEMPRE antes que cualquier classifier de Training, y su vocabulario
 * cerrado incluye "tengo un problema" — una frase genérica que colisiona
 * con una declaración de salud real durante el health screening ("tengo un
 * problema de desviación en la columna"), enviándola a Customer Care en
 * vez de HealthScreeningRequirement::apply().
 *
 * Estos tests prueban el pipeline real completo (mismo patrón que
 * SafetySignalPreRoutingScreenTest.php: vía el Job, no la clase en
 * aislamiento) para demostrar que HealthScreeningPrecedencePreRoutingScreen
 * cierra exactamente ese hallazgo, sin modificar CustomerServiceEscalationDetector
 * ni el Router.
 *
 * Corrección (revisión pre-commit) — todos los Tenant aquí que SÍ deben
 * participar en el screening llevan `primary_domain: 'training'`
 * explícito: el screen ahora tiene un gate de dominio ANTES de cualquier
 * consulta, así que un Tenant sin ese valor nunca lo alcanzaría sin
 * importar el resto del fixture.
 */
function sendHealthPrecedenceTestMessage(Tenant $tenant, string $from, string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

function healthPrecedenceContext(Tenant $tenant, string $from, string $body): ExecutionContext
{
    return new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage($from, $body, 'wamid.'.uniqid(), 'text', null),
    );
}

it('routes a health declaration containing "tengo un problema" to Training when health_screening is the current pending question', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'primary_domain' => 'training']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    // Perfil con TODO lo anterior a health_screening ya satisfecho
    // (nombre/objetivo/nivel/ubicación/equipo) — firstPendingBlocking()
    // debe resolver exactamente 'health_screening' como la pregunta actual.
    TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'health_screening_asked' => false,
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'extracted' => [
                'health_declaration_category' => 'possible_injury',
                'health_condition_text' => 'problema de desviación en la columna',
                'functional_limitation_text' => 'no puedo cargar peso en el hombro',
            ],
            'next_action' => 'complete_onboarding',
            'response' => 'Entendido, lo tendré en cuenta.',
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    sendHealthPrecedenceTestMessage($tenant, '573001112233', 'Tengo en cuenta que tengo un problema de desviación en la columna leve');

    // Nunca un CustomerServiceRequest — la declaración llegó al screening real.
    expect(\App\CustomerCare\Models\CustomerServiceRequest::where('contact_id', $contact->id)->exists())->toBeFalse();
    expect(DeclaredHealthCondition::where('contact_id', $contact->id)->exists())->toBeTrue();
});

it('leaves "tengo un problema con el pago" going to Customer Care when there is no pending health screening (no regression)', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'primary_domain' => 'training']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    // Onboarding ya completo — health_screening ya no es la pregunta pendiente.
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendHealthPrecedenceTestMessage($tenant, '573001112233', 'Tengo un problema con el pago');

    expect(\App\CustomerCare\Models\CustomerServiceRequest::where('contact_id', $contact->id)->exists())->toBeTrue();
    expect(DeclaredHealthCondition::where('contact_id', $contact->id)->exists())->toBeFalse();
});

it('does not claim the pipeline for a Contact with no TrainingProfile at all — falls through to Customer Care normally', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'primary_domain' => 'training']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendHealthPrecedenceTestMessage($tenant, '573001112233', 'Tengo un problema con el pago');

    expect(\App\CustomerCare\Models\CustomerServiceRequest::where('contact_id', $contact->id)->exists())->toBeTrue();
});

it('a real safety emergency still wins over health screening precedence (SafetySignalPreRoutingScreen runs first)', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'primary_domain' => 'training']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    sendHealthPrecedenceTestMessage($tenant, '573001112233', 'Me duele mucho el pecho, no puedo seguir');

    expect(TrainingProfile::where('contact_id', $contact->id)->first()->safety_status)
        ->toBe(\App\Training\Enums\SafetyStatus::FlaggedForReview);
    // Nunca llegó a health screening — SafetySignal se quedó con el turno.
    expect(DeclaredHealthCondition::where('contact_id', $contact->id)->exists())->toBeFalse();
});

// ── Corrección pre-commit — gate explícito de dominio (A-D) ─────────────

it('A: Training tenant with health_screening pending -> the screen claims the pipeline', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'training']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    $screen = app(HealthScreeningPrecedencePreRoutingScreen::class);

    expect($screen->screen(healthPrecedenceContext($tenant, '573001112233', 'cualquier cosa')))->toBeTrue();
});

it('B: Training tenant with a DIFFERENT requirement pending -> the screen does not claim the pipeline', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'training']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    // 'goal' explícitamente sin responder -> firstPendingBlocking() resuelve
    // GoalRequirement (anterior a HealthScreeningRequirement en el registro),
    // nunca health_screening — a pesar de que health_screening_asked
    // también esté en su estado "sin preguntar" por defecto de la columna.
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'goal' => null]);

    $screen = app(HealthScreeningPrecedencePreRoutingScreen::class);

    expect($screen->screen(healthPrecedenceContext($tenant, '573001112233', 'cualquier cosa')))->toBeFalse();
});

it('C: a non-Training tenant never claims the pipeline, even with a fixture shaped to match health_screening', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => null]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => false]);

    $screen = app(HealthScreeningPrecedencePreRoutingScreen::class);

    expect($screen->screen(healthPrecedenceContext($tenant, '573001112233', 'tengo un problema')))->toBeFalse();
});

it('D: a non-Training tenant never queries Contact/TrainingProfile at all to evaluate this screen', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => null]);
    // A propósito: NINGÚN Contact/TrainingProfile creado — si el gate de
    // dominio no actuara ANTES de la consulta, esto seguiría funcionando
    // igual (Contact::where(...)->first() === null -> false), así que la
    // única forma honesta de probar "nunca consulta" es contar las queries
    // reales ejecutadas contra esas tablas, no solo el resultado booleano.
    $screen = app(HealthScreeningPrecedencePreRoutingScreen::class);

    DB::enableQueryLog();
    $result = $screen->screen(healthPrecedenceContext($tenant, '573001112233', 'tengo un problema'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($result)->toBeFalse();
    $touchedContactOrProfile = collect($queries)->contains(
        fn (array $query) => str_contains($query['query'], 'contacts') || str_contains($query['query'], 'training_profiles')
    );
    expect($touchedContactOrProfile)->toBeFalse();
    expect($queries)->toBeEmpty(); // el gate actúa antes de CUALQUIER query, no solo las de Contact
});
