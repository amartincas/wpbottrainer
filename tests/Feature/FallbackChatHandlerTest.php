<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Handlers\FallbackChatHandler;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;

/**
 * P1-B (corrección D2) — no existía ninguna suite dedicada a
 * FallbackChatHandler antes de esta tarea (confirmado por búsqueda previa
 * a escribir este archivo). Cubre específicamente el guard
 * DUPLICATE_LEAD_SKIPPED y su interacción con los Contact "stub"
 * administrativos que un PreRoutingScreen de atribución (Acquisition, o de
 * forma más rara Referrals) puede haber creado milisegundos antes para el
 * mismo tenant+teléfono.
 *
 * `fallbackReadyTenant()`/`fallbackContext()`/`fallbackFakeAi()` — helpers
 * propios de este archivo (prefijo "fallback"), mismo criterio del resto
 * de la suite para evitar colisión de funciones globales entre archivos de
 * test.
 */
function fallbackReadyTenant(): Tenant
{
    return Tenant::factory()->create([
        'ai_provider' => 'openai',
        'ai_api_key' => 'sk-test-fake-key',
        'system_prompt' => 'Eres un asistente de ventas.',
    ]);
}

function fallbackContext(Tenant $tenant, string $from, string $body): ExecutionContext
{
    return new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage($from, $body, 'wamid-fallback-test', 'text', null),
    );
}

/**
 * Una única respuesta de IA, reutilizada tanto para la respuesta principal
 * como para la extracción de datos del lead (FallbackChatHandler hace 2
 * llamadas a IA por turno cuando corresponde crear un lead). Contiene
 * [LEAD_COMPLETE] — eso solo basta, por shouldCreateLeadFromResponse(),
 * para que $hasLeadToken sea true y dispare la ruta de creación de lead,
 * sin importar que la segunda llamada (extracción) no logre parsear esta
 * misma respuesta como JSON válido (cae a extracción por regex, que puede
 * no encontrar nada — irrelevante para lo que este archivo prueba: el
 * guard de duplicado, no la calidad de la extracción).
 */
function fallbackFakeAi(): void
{
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '¡Gracias por tu compra! Tu pedido está confirmado. [LEAD_COMPLETE]']]],
        ], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);
}

// ── 1: Contact reciente CON summary de lead real -> sigue siendo duplicado ──

it('1: a recent Contact with a real-lead-shaped summary is still treated as a duplicate — no second Contact is created', function () {
    $tenant = fallbackReadyTenant();
    Contact::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_phone' => '573000000001',
        'summary' => '¡Gracias por tu compra! Tu pedido está confirmado.',
        'created_at' => now()->subMinutes(10),
    ]);
    fallbackFakeAi();

    (new FallbackChatHandler())->handle(fallbackContext($tenant, '573000000001', 'Confirmo mi compra, mi nombre es Juan'));

    expect(Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573000000001')->count())->toBe(1);
});

// ── 2: Contact reciente con summary de Acquisition -> NO es duplicado ──

/**
 * Hito A (Contact Identity, hallazgo de la prueba E2E real) — expectativa
 * actualizada: antes de este hito, FallbackChatHandler usaba Contact::create()
 * puro, así que un stub de atribución "NO tratado como duplicado" producía
 * una SEGUNDA fila real (el bug exacto del incidente de staging con Juan
 * Pablo — dos Contacts para el mismo tenant+teléfono). Con updateOrCreate()
 * (misma clave tenant_id+customer_phone que usan los otros 8 puntos de
 * creación de Contact del repositorio), el stub se ACTUALIZA in situ con
 * los datos reales del lead — la intención original del test (el stub de
 * atribución nunca bloquea que el lead real se registre) se preserva
 * intacta; solo cambia CÓMO se registra (misma fila, no una nueva).
 */
it('2: a recent Contact stamped by Acquisition attribution is NOT treated as a duplicate — the real lead updates it in place, never a second Contact', function () {
    $tenant = fallbackReadyTenant();
    $stub = Contact::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_phone' => '573000000002',
        'summary' => 'Registro de Acquisition (atribución)',
        'created_at' => now()->subMinutes(5),
    ]);
    fallbackFakeAi();

    (new FallbackChatHandler())->handle(fallbackContext($tenant, '573000000002', 'Confirmo mi compra, mi nombre es Ana'));

    $contacts = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573000000002')->get();
    expect($contacts)->toHaveCount(1); // el stub se actualiza, nunca un segundo Contact
    expect($contacts->first()->id)->toBe($stub->id);
    // El lead real lleva el texto de cierre de la IA (sin el token), nunca
    // la convención "Registro de " — confirma que la fila pasó a ser un
    // lead genuino, no que se quedó como stub administrativo.
    expect($contacts->first()->summary)->not->toStartWith('Registro de ');
    expect($contacts->first()->summary)->toContain('Tu pedido está confirmado');
});

// ── 3: Contact reciente con summary de Referral attribution -> NO es duplicado (cubre el caso latente) ──

it('3: a recent Contact stamped by Referral attribution is NOT treated as a duplicate either — same generic convention covers the latent Referrals case', function () {
    $tenant = fallbackReadyTenant();
    $stub = Contact::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_phone' => '573000000003',
        'summary' => 'Registro de Referrals (atribución)',
        'created_at' => now()->subMinutes(2),
    ]);
    fallbackFakeAi();

    (new FallbackChatHandler())->handle(fallbackContext($tenant, '573000000003', 'Confirmo mi compra, mi nombre es Luis'));

    $contacts = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573000000003')->get();
    expect($contacts)->toHaveCount(1);
    expect($contacts->first()->id)->toBe($stub->id);
    expect($contacts->first()->summary)->not->toStartWith('Registro de ');
});

// ── 4: Contact antiguo (>1h) con summary de lead real -> conserva el comportamiento legacy de ventana ──

/**
 * Hito A — expectativa actualizada por el mismo motivo que el test 2: el
 * criterio real que este test protege ("un Contact fuera de la ventana de
 * 1h nunca bloquea un nuevo lead") sigue exactamente igual — lo único que
 * cambia es que, al no haber ya ningún Contact NUEVO creado por Referral/
 * Acquisition (este escenario simula un Contact viejo y ya resuelto),
 * updateOrCreate() actualiza esa misma fila en vez de crear una segunda.
 */
it('4: an old Contact (older than the 1-hour window), even with a real-lead-shaped summary, never blocks a new lead — the time window still applies exactly as before', function () {
    $tenant = fallbackReadyTenant();
    $old = Contact::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_phone' => '573000000004',
        'summary' => '¡Gracias por tu compra anterior!',
        'created_at' => now()->subHours(2),
    ]);
    fallbackFakeAi();

    (new FallbackChatHandler())->handle(fallbackContext($tenant, '573000000004', 'Confirmo mi compra, mi nombre es Sofía'));

    $contacts = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573000000004')->get();
    expect($contacts)->toHaveCount(1); // nunca un segundo Contact — la fila existente se actualiza
    expect($contacts->first()->id)->toBe($old->id);
    expect($contacts->first()->summary)->toContain('Tu pedido está confirmado'); // el lead nuevo SÍ se registró
});

// ── 5: summary NULL — documentado como inalcanzable bajo el esquema actual ──

it('5: summary cannot be NULL under the current schema — documents that the defensive whereNull() branch is unreachable today, not an assumption', function () {
    // create_contacts_table.php define `summary` como `$table->text('summary')`
    // SIN `->nullable()` y sin default — un intento real de persistir NULL
    // (aquí, vía Eloquent, pero el resultado sería idéntico con un INSERT
    // crudo: es una restricción NOT NULL a nivel de motor de BD) falla con
    // una violación de integridad. Esto confirma que la rama
    // `whereNull('summary')` agregada en FallbackChatHandler es una
    // salvaguarda defensiva para un estado que el esquema actual no permite
    // producir — no una suposición sin verificar, y no debilita ninguna
    // garantía existente si el esquema cambiara en el futuro.
    $tenant = fallbackReadyTenant();

    expect(fn () => Contact::create([
        'tenant_id' => $tenant->id,
        'customer_phone' => '573000000005',
        'summary' => null,
    ]))->toThrow(QueryException::class);
});
