<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Payments\Enums\PaymentStatus;
use App\Training\Enums\TrainingAccessStatus;
use App\Training\Support\TrainingAccessAdministrationService;
use Illuminate\Support\Facades\Http;

/**
 * Hito 15 — Escenario A: usuario nuevo -> onboarding -> Trial automático
 * (concedido al INTENTAR entrenar, elegible = nunca tuvo Trial Y nunca tuvo
 * un Payment confirmado) -> primer entrenamiento. Vía el Job real
 * (ProcessWhatsAppMessage), no llamadas directas a servicios aislados.
 */
function commercialTrainingMessage(Tenant $tenant, string $from, ?string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

it('a brand-new Contact, after completing onboarding, gets an automatic Trial and their first WorkoutSession — no superadmin action required', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'trial_duration_days' => 5]);
    // Perfil completo ya sembrado (el recorrido de onboarding turno a turno
    // ya está probado exhaustivamente en TrainingConversationFlowTest — este
    // test se enfoca en la frontera Onboarding->Trial->Entrenamiento).
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001110001']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'safety_signal_text' => null, 'intents' => ['continue_training'], 'training_reply' => null,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    commercialTrainingMessage($tenant, '573001110001', 'Dame mi entrenamiento de hoy');

    $access = TrainingAccess::where('contact_id', $contact->id)->sole();
    expect($access->status)->toBe(TrainingAccessStatus::Trial);
    expect($access->granted_by)->toBe('system_auto_trial');
    expect($access->trial_granted_at)->not->toBeNull();
    expect($access->expires_at->diffInDays(now(), true))->toBeGreaterThan(4)->toBeLessThan(6);
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '🔥 Tu entrenamiento de hoy'));
});

it('the automatic Trial duration comes from the Tenant own trial_duration_days, never a fixed value', function () {
    $shortTrialTenant = Tenant::factory()->create(['ai_provider' => 'openai', 'trial_duration_days' => 2]);
    $contact = Contact::factory()->create(['tenant_id' => $shortTrialTenant->id, 'customer_phone' => '573001110002']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'safety_signal_text' => null, 'intents' => ['continue_training'], 'training_reply' => null,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    commercialTrainingMessage($shortTrialTenant, '573001110002', 'Dame mi entrenamiento de hoy');

    $access = TrainingAccess::where('contact_id', $contact->id)->sole();
    expect($access->expires_at->diffInDays(now(), true))->toBeGreaterThan(1)->toBeLessThan(3);
});

it('a Contact acquired via a Referral is just as eligible for the automatic Trial as one who arrived directly — origin never affects eligibility', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $code = \App\Referrals\Models\ReferralCode::factory()->create(['contact_id' => $referrer->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'extracted' => [
                'name' => null, 'goal' => null, 'experience_level' => null,
                'primary_focus' => null, 'secondary_focus' => null, 'training_location' => null,
                'restrictions' => null, 'available_equipment' => null, 'equipment_fully_equipped' => null,
                'sessions_per_week' => null, 'age' => null, 'sex' => null, 'weight_kg' => null,
                'height_cm' => null, 'safety_signal_text' => null,
                'health_declaration_category' => null, 'health_condition_text' => null, 'functional_limitation_text' => null,
            ],
            'next_action' => 'ask_name',
            'response' => '¿Cómo te gustaría que te llame?',
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    // Mensaje reenviado desde wa.me — la atribución de Referral corre en
    // PreRoutingScreener, no afecta el enrutamiento normal a Training.
    commercialTrainingMessage($tenant, '573009990001', "Hola! Quiero entrenar 💪 {$code->code}");

    $referred = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573009990001')->sole();
    expect(\App\Referrals\Models\Referral::where('referred_contact_id', $referred->id)->exists())->toBeTrue();

    // El onboarding arrancó con normalidad — la atribución no lo bloquea ni
    // lo acelera. La elegibilidad del Trial (probada exhaustivamente en
    // AutomaticTrialProvisionerTest) tampoco depende de este atributo.
    expect(TrainingProfile::where('contact_id', $referred->id)->exists())->toBeTrue();
});

it('a new session delivery is ordered Trial notice -> header -> only the first exercise, in that exact order', function () {
    // Ronda 2 (piloto real), Cambio 2: el aviso de Trial (paso 3, tan
    // pronto se concede) siempre antecede a la entrega. H16.2 Fase 1
    // (entrega progresiva): la entrega ya NO enumera todos los ejercicios
    // de una sola vez — solo el primero (order más bajo) — y ya no existe
    // un mensaje fijo de "instrucciones de ejecución" al final (retirado en
    // H16.2 Fase 1.1, ver ExecutionReportService/ExecutionReportRecorder).
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'trial_duration_days' => 5]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001110004']);
    // experience_level/difficulty_level fijos y coincidentes en ambos
    // ejercicios — ambas factories usan un valor ALEATORIO por defecto, y
    // TrainingEngine::sortCandidates() desempata primero por
    // coincidencia de nivel: sin fijarlo, el orden entre Flexiones y
    // Sentadilla dejaría de ser determinista (id ascendente solo desempata
    // *después* del nivel).
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true, 'experience_level' => \App\Training\Enums\ExperienceLevel::Intermediate]);
    Exercise::factory()->create(['muscle_group' => 'chest', 'name' => 'Flexiones', 'difficulty_level' => 'intermediate']);
    Exercise::factory()->create(['muscle_group' => 'legs', 'name' => 'Sentadilla', 'difficulty_level' => 'intermediate']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'safety_signal_text' => null, 'intents' => ['continue_training'], 'training_reply' => null,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    commercialTrainingMessage($tenant, '573001110004', 'Dame mi entrenamiento de hoy');

    $replyTexts = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body'))
        ->filter()
        ->values();

    $session = WorkoutSession::where('contact_id', $contact->id)->sole();
    expect($session->workoutExercises->count())->toBeGreaterThan(1); // catálogo real con más de 1 candidato

    expect($replyTexts->first())->toContain('período de prueba gratis');
    expect($replyTexts->get(1))->toBe('🔥 Tu entrenamiento de hoy');
    // Header + exactamente 1 tarjeta de ejercicio (la del primero, order=1)
    // — nunca las demás, nunca un mensaje de cierre fijo adicional.
    expect($replyTexts)->toHaveCount(3);
    expect($replyTexts->last())->toContain('1. *Flexiones*');
});

it('a Contact who already had a confirmed Payment historically is never granted an automatic Trial, even if somehow their TrainingAccess row went missing', function () {
    // Caso defensivo (probado a fondo en AutomaticTrialProvisionerTest) —
    // aquí se confirma el mismo comportamiento a través del Job real.
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001110003']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    Payment::factory()->create(['contact_id' => $contact->id, 'status' => PaymentStatus::Confirmed]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    commercialTrainingMessage($tenant, '573001110003', 'Dame mi entrenamiento de hoy');

    expect(TrainingAccess::where('contact_id', $contact->id)->exists())->toBeFalse();
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'activar tu acceso'));
});
