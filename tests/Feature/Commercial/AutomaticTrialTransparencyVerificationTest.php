<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutSession;
use App\Training\Support\TrainingAccessAdministrationService;
use Illuminate\Support\Facades\Http;

/**
 * Hito 15 — verificación de transparencia: el ÚNICO cambio funcional
 * introducido por el Trial automático debe ser EL MECANISMO por el cual el
 * Contact obtiene acceso — nunca un nuevo branch conversacional, nueva
 * clasificación de Intent, ni un comportamiento distinto de entrega del
 * primer entrenamiento.
 *
 * Metodología: se ejecuta EL MISMO guion de conversación (mismo mensaje que
 * completa el onboarding, mismo AI fake, mismo catálogo de ejercicios) dos
 * veces — una con TrainingAccess PRE-EXISTENTE (equivale al estado final
 * del flujo administrativo manual: el admin ya otorgó el Trial ANTES de
 * este turno, exactamente como funcionaba antes de H15) y otra sin ningún
 * TrainingAccess (depende del Trial automático de H15, otorgado DENTRO de
 * este mismo turno) — y se compara el resultado byte a byte: misma
 * respuesta de WhatsApp, mismo número de mensajes salientes, mismo
 * WorkoutSession generado, mismos ejercicios prescritos.
 */
function transparencyReadyProfile(Tenant $tenant, string $phone): Contact
{
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => $phone]);
    // Todos los campos que influyen en la prescripción (experience_level,
    // goal, etc.) fijados EXPLÍCITAMENTE e IDÉNTICOS entre ambas ramas —
    // la factory por defecto los randomiza (fake()->randomElement(...)),
    // lo que produciría una prescripción genuinamente distinta sin que eso
    // tenga nada que ver con el mecanismo de acceso.
    TrainingProfile::factory()->create([
        'contact_id' => $contact->id,
        'goal' => \App\Training\Enums\TrainingGoal::BuildMuscle,
        'experience_level' => \App\Training\Enums\ExperienceLevel::Beginner,
        'training_location' => \App\Training\Enums\TrainingLocation::Gym,
        'available_equipment' => [],
        'health_screening_asked' => true,
    ]);

    return $contact->fresh();
}

function transparencySeedCatalog(): void
{
    Exercise::factory()->create(['muscle_group' => 'chest', 'name' => 'Flexiones']);
    Exercise::factory()->create(['muscle_group' => 'legs', 'name' => 'Sentadilla']);
    Exercise::factory()->create(['muscle_group' => 'back', 'name' => 'Remo']);
}

function transparencyFakeCoachContinueTraining(): void
{
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'safety_signal_text' => null, 'intents' => ['continue_training'], 'training_reply' => null,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);
}

function transparencySendMessage(Tenant $tenant, string $from, string $body): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

/**
 * TrainingEngine::selectExercises() no garantiza un orden estable entre
 * corridas distintas — el número ordinal ("1.", "2.", "3.") de cada
 * ejercicio en el mensaje depende de esa selección, no del mecanismo de
 * acceso. Se normaliza quitando el prefijo ordinal antes de comparar, para
 * que la comparación sea sobre el CONTENIDO real (técnica, formato), nunca
 * sobre una posición que ya es no determinista incluso entre dos corridas
 * con TrainingAccess pre-existente idéntico.
 */
function transparencyNormalizeOrdinal(string $text): string
{
    return preg_replace('/^\d+\.\s*/', '', $text);
}

it('produces the exact same outbound reply, WorkoutSession, and prescribed exercises whether TrainingAccess pre-existed (manual-grant-equivalent) or was granted automatically inline this same turn', function () {
    // El catálogo de Exercise NO es tenant-scoped (catálogo global,
    // compartido — ver ExerciseResource) — se siembra UNA sola vez, para
    // ambas ramas, exactamente como en producción real.
    transparencySeedCatalog();

    // ── Rama A: TrainingAccess YA existe antes del turno (equivale al
    // estado final de un grant manual anterior — el comportamiento
    // pre-H15 una vez que el admin ya actuó). ─────────────────────────
    $tenantA = Tenant::factory()->create();
    $contactA = transparencyReadyProfile($tenantA, '573001170001');
    TrainingAccess::factory()->create(['contact_id' => $contactA->id]);

    transparencyFakeCoachContinueTraining();
    transparencySendMessage($tenantA, '573001170001', 'Dame mi entrenamiento de hoy');

    $sessionA = WorkoutSession::where('contact_id', $contactA->id)->sole();
    $exerciseNamesA = $sessionA->workoutExercises->pluck('exercise_snapshot.name')->sort()->values()->all();
    $replyTextsA = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body'))
        ->filter()
        ->values()
        ->all();

    // ── Rama B: SIN TrainingAccess — depende del Trial automático de H15,
    // otorgado dentro de este mismo turno (paso 3, antes de step 4-6). ──
    $tenantB = Tenant::factory()->create();
    $contactB = transparencyReadyProfile($tenantB, '573001170002');

    transparencyFakeCoachContinueTraining();
    transparencySendMessage($tenantB, '573001170002', 'Dame mi entrenamiento de hoy');

    $sessionB = WorkoutSession::where('contact_id', $contactB->id)->sole();
    $exerciseNamesB = $sessionB->workoutExercises->pluck('exercise_snapshot.name')->sort()->values()->all();
    $replyTextsB = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body'))
        ->filter()
        ->values()
        ->all();

    // ── El único cambio funcional debe ser CÓMO se otorgó el acceso —
    // nunca el resultado conversacional a partir de ahí. ────────────────
    expect(TrainingAccess::where('contact_id', $contactA->id)->sole()->granted_by)->not->toBe('system_auto_trial');
    expect(TrainingAccess::where('contact_id', $contactB->id)->sole()->granted_by)->toBe('system_auto_trial');

    // Hito 15.1 (Ronda 2, Cambio 2) — el ÚNICO mensaje adicional legítimo
    // en B es el aviso de Trial: nunca aparece en A porque su
    // TrainingAccess ya existía ANTES del turno (no hubo concesión EN este
    // turno, ver transparencyReadyProfile()+TrainingAccess::factory()
    // arriba). Se envía en el paso 3 (antes que cualquier otra cosa del
    // turno), así que siempre es el PRIMER mensaje saliente. Se separa
    // explícitamente antes de comparar mensaje a mensaje el resto, que
    // debe seguir siendo idéntico entre A y B.
    expect(count($replyTextsB))->toBe(count($replyTextsA) + 1);
    $trialNoticeB = array_shift($replyTextsB);
    expect($trialNoticeB)->toContain('período de prueba gratis');
    expect($trialNoticeB)->toContain((string) $tenantB->trial_duration_days);
    expect($replyTextsA)->not->toContain($trialNoticeB);

    expect($exerciseNamesB)->toBe($exerciseNamesA); // misma prescripción
    expect(count($replyTextsB))->toBe(count($replyTextsA)); // mismo número de mensajes, tras remover el aviso de Trial
    // Mismo CONTENIDO exacto en todos los mensajes, ignorando únicamente el
    // prefijo ordinal (ver transparencyNormalizeOrdinal) — nunca el
    // mecanismo de acceso decide ese orden.
    $normalizedA = collect($replyTextsA)->map('transparencyNormalizeOrdinal')->sort()->values()->all();
    $normalizedB = collect($replyTextsB)->map('transparencyNormalizeOrdinal')->sort()->values()->all();
    expect($normalizedB)->toBe($normalizedA);
});

it('never sends the Trial-granted notice again in a later turn where the Trial is already active — only in the turn of the actual grant', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = transparencyReadyProfile($tenant, '573001170004');
    // Trial YA otorgado antes de este turno — TrainingAccessGate ya
    // autoriza directamente, AutomaticTrialProvisioner nunca se ejecuta.
    app(TrainingAccessAdministrationService::class)->grantAutomaticTrial($contact, 5);

    transparencyFakeCoachContinueTraining();
    transparencySendMessage($tenant, '573001170004', 'Dame mi entrenamiento de hoy');

    $replyTexts = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body'))
        ->filter()
        ->values();

    expect($replyTexts->contains(fn ($t) => str_contains($t, 'período de prueba gratis')))->toBeFalse();
});

it('sends the Trial-granted notice exactly once, in the same turn it is granted, without any additional AI call', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai', 'trial_duration_days' => 5]);
    $contact = transparencyReadyProfile($tenant, '573001170005');
    // Sin TrainingAccess previo -> se concede EN este turno (paso 3).

    transparencyFakeCoachContinueTraining();
    transparencySendMessage($tenant, '573001170005', 'Dame mi entrenamiento de hoy');

    $aiCallsCount = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.openai.com'))->count();
    expect($aiCallsCount)->toBe(1); // única llamada de IA del turno (Coach) — el aviso es 100% determinista, sin IA

    $replyTexts = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body'))
        ->filter()
        ->values();

    $trialNotices = $replyTexts->filter(fn ($t) => str_contains($t, 'período de prueba gratis'));
    expect($trialNotices)->toHaveCount(1);
    expect($replyTexts->first())->toContain('período de prueba gratis'); // primer mensaje del turno (paso 3)
});

it('regression guard: hasActiveAccessAwaitingFirstWorkout() NEVER fires for a Trial-status TrainingAccess (Trial automático nunca activa este mecanismo, solo Active lo hace, sin cambios)', function () {
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001170003']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    app(TrainingAccessAdministrationService::class)->grantAutomaticTrial($contact, 5);

    // Sin palabra clave ni señal de estado -> el Router debe caer a
    // fallback_chat (el chat genérico), que sí hace una llamada real de
    // IA — se simula solo para que el test no dependa de red real; el
    // contenido no es lo que se verifica aquí.
    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Hola, ¿en qué puedo ayudarte?']]]]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    // Mensaje SIN palabra clave de Training y SIN sesión pendiente — si
    // hasActiveAccessAwaitingFirstWorkout() disparara para Trial, esto
    // enrutaría a Training (y probablemente a una llamada real de IA no
    // simulada, fallando el test). Con Trial (no Active), el Router NO
    // debe enrutar aquí a Training en absoluto — cae a fallback_chat,
    // exactamente el mismo comportamiento que un Trial otorgado
    // MANUALMENTE ya tenía antes de H15 (el método solo comprueba
    // status === Active, ver TrainingIntentClassifier::hasActiveAccessAwaitingFirstWorkout()).
    transparencySendMessage($tenant, '573001170003', 'hola');

    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(0);
});
