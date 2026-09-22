<?php

use App\Core\Messaging\Dispatcher;
use App\Core\Messaging\DomainFallbackResolver;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Ingest;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Core\Messaging\Router;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

/**
 * Hito A (Entry/Domain Fallback, hallazgo de la prueba E2E real) — un
 * usuario nuevo de un tenant Training cuyo primer mensaje no contiene
 * ninguna keyword reconocible por TrainingIntentClassifier (ej. "Quiero
 * unirme a WpbotTrainer") caía en App\Handlers\FallbackChatHandler — un
 * chatbot de e-commerce legacy y compartido — que fabricaba una rutina vía
 * IA libre y creaba un segundo Contact desconectado de TrainingProfile.
 *
 * Cubre los 6 casos verificados en el diseño (A-F): Training gana el
 * residuo de FallbackChat solo cuando NINGÚN classifier real (explícito o
 * contextual, de cualquier dominio) reconoció el mensaje — nunca antes,
 * nunca en lugar de ellos.
 */
function resolveEntryIntent(Tenant $tenant, string $body): Intent
{
    $context = new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage($tenant->id.'-fixed-phone', $body, 'wamid.'.uniqid(), 'text', null),
    );

    $intent = app(Router::class)->route($context);

    if ($intent === Intent::FallbackChat) {
        $intent = app(DomainFallbackResolver::class)->resolve($context) ?? $intent;
    }

    return $intent;
}

// ── A/D: mensaje no clasificable, tenant Training → Training ────────────

it('A: a non-classifiable message for a Training tenant resolves to Intent::Training', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'training']);

    expect(resolveEntryIntent($tenant, 'Quiero unirme a WpbotTrainer 💪 REF-337BDA'))->toBe(Intent::Training);
});

// ── B/D: un intent específico reconocido nunca es reemplazado ───────────

it('B: an explicit Training keyword still resolves to Training directly (never via domain fallback)', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'training']);

    expect(resolveEntryIntent($tenant, 'Quiero entrenar hoy'))->toBe(Intent::Training);
});

it('D: an explicit Payment keyword for a Training tenant still resolves to Payment, not Training', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'training']);

    expect(resolveEntryIntent($tenant, 'Quiero pagar mi membresía'))->toBe(Intent::Payment);
});

// ── C: Customer Care (Tier 0) sigue ganando siempre ──────────────────────

it('C: an explicit Customer Care escalation phrase for a Training tenant still resolves to CustomerCare', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'training']);

    expect(resolveEntryIntent($tenant, 'Tengo un problema con el pago'))->toBe(Intent::CustomerCare);
});

// ── E: tenant sin primary_domain=training → comportamiento actual intacto ─

it('E: a non-classifiable message for a Tenant with primary_domain=null stays FallbackChat', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => null]);

    expect(resolveEntryIntent($tenant, 'Hola! Quiero unirme a WpbotTrainer 💪'))->toBe(Intent::FallbackChat);
});

// ── F: contexto ya existente sigue ganando antes que el fallback de dominio ─

it('F: an already-onboarded Training contact with an ambiguous message resolves via TrainingContextualIntentClassifier, not the domain fallback', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'training']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => $tenant->id.'-fixed-phone']);
    \App\Models\TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    \App\Models\WorkoutSession::factory()->create(['contact_id' => $contact->id]); // Scheduled pendiente

    // Este mensaje no matchea ninguna keyword explícita, pero SÍ hay
    // contexto real (WorkoutSession pendiente) — TrainingContextualIntentClassifier
    // (Tier 2) debe reconocerlo antes de que el Router agote los 4 Tiers.
    expect(resolveEntryIntent($tenant, 'ya la hice'))->toBe(Intent::Training);
});

// ── Regresión E2E completa: reproduce el caso real de Juan Pablo con el fix ──

it('reproduces the real staging incident end-to-end: single Contact, Training onboarding starts, no fabricated routine', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'training', 'ai_provider' => 'openai']);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'name' => null, 'age' => null, 'goal' => null, 'experience_level' => null,
                'sessions_per_week' => null, 'health_condition_text' => null,
            ])]]],
        ]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    $job = new ProcessWhatsAppMessage($tenant, '573218832789', 'Hola! Quiero unirme a WpbotTrainer 💪', 'wamid.IN1', 'text');
    app()->call([$job, 'handle']);

    // Un único Contact — nunca el fantasma de FallbackChatHandler.
    expect(Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573218832789')->count())->toBe(1);

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573218832789')->first();
    expect(\App\Models\TrainingProfile::where('contact_id', $contact->id)->exists())->toBeTrue();

    // Nunca la respuesta fabricada de FallbackChatHandler ("[LEAD_COMPLETE]",
    // rutina de 5 días inventada) — la conversación entró al onboarding real.
    $lastReply = \App\Models\WhatsAppMessage::where('tenant_id', $tenant->id)
        ->where('customer_phone', '573218832789')->where('role', 'assistant')->latest('id')->first();
    expect($lastReply)->not->toBeNull();
    expect($lastReply->content)->not->toContain('LEAD_COMPLETE');
});
