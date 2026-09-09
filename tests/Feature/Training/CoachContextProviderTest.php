<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\ExerciseSet;
use App\Models\ReminderSuggestion;
use App\Models\Tenant;
use App\Models\TrainingProfile;
use App\Models\WhatsAppMessage;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Context\CoachContextProvider;
use App\Training\Enums\HistoryExerciseOutcome;
use App\Training\Enums\ReminderSuggestionOrigin;
use App\Training\Enums\ReminderSuggestionStatus;
use App\Training\Enums\TrackingType;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Support\BodyRegionCanonicalMapper;
use App\Training\Support\CoachFactsFormatter;
use App\Training\Support\ProgressionEvaluator;
use App\Training\Support\SafetyRestrictionResolver;
use App\Training\Support\TrainingHistoryContextProvider;

/**
 * Bloque 9 (D052) — CoachContextProvider: compone CoachContext reutilizando
 * TrainingHistoryContextProvider/ProgressionEvaluator, nunca recalculando.
 */
function coachContextProvider(): CoachContextProvider
{
    $safetyResolver = new SafetyRestrictionResolver(new BodyRegionCanonicalMapper);

    return new CoachContextProvider(
        new TrainingHistoryContextProvider($safetyResolver),
        new ProgressionEvaluator,
        new \App\CustomerCare\Support\FaqRelevanceDetector,
        new \App\CustomerCare\Support\CustomerServiceEscalationDetector,
        new \App\CustomerCare\Support\FaqMatcher,
    );
}

function executionContextFor(Tenant $tenant, string $from): ExecutionContext
{
    return new ExecutionContext($tenant, null, new IngestedMessage($from, 'mensaje de prueba', null, 'text', null));
}

it('returns null data when the contact does not exist', function () {
    $tenant = Tenant::factory()->create();

    $fragment = coachContextProvider()->provide(executionContextFor($tenant, '5730000000'));

    expect($fragment->label)->toBe('coach_context');
    expect($fragment->data)->toBeNull();
});

it('currentSession is null when the contact never trained', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000001']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000001'))->data;

    expect($context->currentSession)->toBeNull();
    expect($context->historyContext->windowSessionsCount)->toBe(0);
    expect($context->progressionEvaluations)->toBe([]);
});

it('resolves the pending Scheduled session as currentSession when one exists', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000002']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $exercise = Exercise::factory()->create(['name' => 'Sentadilla']);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
        'prescribed_sets' => 3, 'prescribed_reps' => 10, 'prescribed_load' => 40,
    ]);

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000002'))->data;

    expect($context->currentSession)->not->toBeNull();
    expect($context->currentSession->workoutSessionId)->toBe($session->id);
    expect($context->currentSession->status)->toBe(WorkoutSessionStatus::Scheduled);
    expect($context->currentSession->exercises)->toHaveCount(1);
    expect($context->currentSession->exercises[0]->name)->toBe('Sentadilla');
    expect($context->currentSession->exercises[0]->outcome)->toBe(HistoryExerciseOutcome::Unreported);
});

it('falls back to the most recent Completed/Skipped session when there is no pending one', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000003']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $exercise = Exercise::factory()->create(['name' => 'Press de banca']);
    $session = WorkoutSession::factory()->completed()->create(['contact_id' => $contact->id]);
    $we = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
    ]);
    $log = ExerciseLog::factory()->create(['workout_exercise_id' => $we->id, 'rpe' => 6]);
    ExerciseSet::factory()->create(['exercise_log_id' => $log->id, 'actual_reps' => 10, 'actual_load' => 40]);

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000003'))->data;

    expect($context->currentSession->workoutSessionId)->toBe($session->id);
    expect($context->currentSession->status)->toBe(WorkoutSessionStatus::Completed);
    expect($context->currentSession->exercises[0]->outcome)->toBe(HistoryExerciseOutcome::Performed);
    expect($context->currentSession->exercises[0]->rpe)->toBe(6);
});

it('computes ProgressionEvaluation for each exercise of the current session, keyed by exerciseId', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000004']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $exercise = Exercise::factory()->create();
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
    ]);

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000004'))->data;

    expect($context->progressionEvaluations)->toHaveKey($exercise->id);
    expect($context->progressionEvaluations[$exercise->id]->exerciseId)->toBe($exercise->id);
});

it('trackingType comes from Exercise::tracking_type, never inferred from prescribedDurationSeconds (correction after Bloque 9 review)', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000009']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);

    // Ejercicio TimeBased en el catálogo, pero SIN prescribed_duration_seconds
    // en este WorkoutExercise (prescripción incompleta) — el heurístico
    // anterior habría inferido erróneamente RepsAndLoad a partir de este
    // dato faltante; la fuente de verdad real (Exercise::tracking_type)
    // sigue siendo TimeBased sin importar el estado de la prescripción.
    $timeBasedExercise = Exercise::factory()->timeBased()->create(['name' => 'Plancha']);
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $timeBasedExercise->id, 'exercise_snapshot' => $timeBasedExercise->toSnapshot(),
        'order' => 1, 'prescribed_reps' => 10, 'prescribed_load' => 20, 'prescribed_duration_seconds' => null,
    ]);

    // Ejercicio RepsAndLoad, pero CON prescribed_duration_seconds poblado
    // (dato inconsistente/legacy) — el heurístico anterior habría inferido
    // erróneamente TimeBased a partir de la sola presencia de ese dato.
    $repsAndLoadExercise = Exercise::factory()->create(['name' => 'Curl de bíceps']);
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $repsAndLoadExercise->id, 'exercise_snapshot' => $repsAndLoadExercise->toSnapshot(),
        'order' => 2, 'prescribed_duration_seconds' => 30,
    ]);

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000009'))->data;

    $byExerciseId = collect($context->currentSession->exercises)->keyBy('exerciseId');

    expect($byExerciseId[$timeBasedExercise->id]->trackingType)->toBe(TrackingType::TimeBased);
    expect($byExerciseId[$repsAndLoadExercise->id]->trackingType)->toBe(TrackingType::RepsAndLoad);
});

it('a deleted exercise (exercise_id null) in the current session is excluded from progressionEvaluations but still appears in exercises', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000005']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $exercise = Exercise::factory()->create(['name' => 'Ejercicio borrado']);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'exercise_snapshot' => $exercise->toSnapshot(),
    ]);
    $exercise->delete();

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000005'))->data;

    expect($context->currentSession->exercises)->toHaveCount(1);
    expect($context->currentSession->exercises[0]->exerciseId)->toBeNull();
    expect($context->currentSession->exercises[0]->name)->toBe('Ejercicio borrado');
    expect($context->progressionEvaluations)->toBe([]);
});

it('profileSnapshot is exactly TrainingHistoryContext->currentProfileSnapshot, never a separate representation', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000006']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000006'))->data;

    expect($context->profileSnapshot)->toBe($context->historyContext->currentProfileSnapshot);
});

it('recentMessages contains the last 10 WhatsAppMessage in chronological order, never more', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000007']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    for ($i = 1; $i <= 12; $i++) {
        WhatsAppMessage::create([
            'tenant_id' => $tenant->id, 'customer_phone' => '5730000007',
            'role' => $i % 2 === 0 ? 'assistant' : 'user', 'content' => "mensaje {$i}",
        ]);
    }

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000007'))->data;

    expect($context->recentMessages)->toHaveCount(10);
    // El más antiguo de los últimos 10 es el mensaje 3; el más reciente el 12.
    expect($context->recentMessages[0]['content'])->toBe('mensaje 3');
    expect($context->recentMessages[9]['content'])->toBe('mensaje 12');
});

it('never modifies any source-of-truth table', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000008']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id]);

    $before = [WorkoutSession::count(), WorkoutExercise::count(), TrainingProfile::count()];

    coachContextProvider()->provide(executionContextFor($tenant, '5730000008'));

    expect(WorkoutSession::count())->toBe($before[0]);
    expect(WorkoutExercise::count())->toBe($before[1]);
    expect(TrainingProfile::count())->toBe($before[2]);
});

// ── pendingReminderSuggestion (D053, corrección post-revisión) ───────────

it('pendingReminderSuggestion reflects the pending ReminderSuggestion via activePendingFor(), reusing it verbatim', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000009']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    ReminderSuggestion::create([
        'tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'origin' => ReminderSuggestionOrigin::UserRequest,
        'proposed_type' => 'training_weekly', 'proposed_params' => ['day' => 'tuesday', 'time' => '19:00', 'recurring' => true],
        'status' => ReminderSuggestionStatus::Pending, 'expires_at' => now()->addHours(24),
    ]);

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000009'))->data;

    expect($context->pendingReminderSuggestion)->not->toBeNull();
    expect($context->pendingReminderSuggestion->day)->toBe('tuesday');
    expect($context->pendingReminderSuggestion->time)->toBe('19:00');
    expect($context->pendingReminderSuggestion->recurring)->toBeTrue();
});

it('pendingReminderSuggestion is null when there is no pending suggestion', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000010']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000010'))->data;

    expect($context->pendingReminderSuggestion)->toBeNull();
});

it('pendingReminderSuggestion stays populated even when 15 unrelated WhatsAppMessage rows push the original offer far outside the 10-message recentMessages window', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000011']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    // El "mensaje de oferta" en sí nunca se persiste literalmente aquí — lo
    // que se demuestra es que, sin importar cuántos mensajes NO relacionados
    // hayan pasado desde entonces (mucho más que el límite de 10 de
    // recentMessages), el HECHO estructurado sigue disponible porque viene
    // de la fila de ReminderSuggestion (BD), nunca de buscar en el chat.
    for ($i = 1; $i <= 15; $i++) {
        WhatsAppMessage::create([
            'tenant_id' => $tenant->id, 'customer_phone' => '5730000011',
            'role' => $i % 2 === 0 ? 'assistant' : 'user', 'content' => "charla sin relación {$i}",
        ]);
    }

    ReminderSuggestion::create([
        'tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'origin' => ReminderSuggestionOrigin::UserRequest,
        'proposed_type' => 'training_weekly', 'proposed_params' => ['day' => 'tuesday', 'time' => '19:00', 'recurring' => true],
        'status' => ReminderSuggestionStatus::Pending, 'expires_at' => now()->addHours(24),
    ]);

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000011'))->data;

    // El historial ya no contiene ningún rastro de la oferta (10 de 15
    // mensajes, todos ajenos al recordatorio) — y aun así el HECHO está.
    $historyMentionsReminder = collect($context->recentMessages)
        ->contains(fn (array $message) => str_contains($message['content'], 'recordarte'));
    expect($historyMentionsReminder)->toBeFalse();
    expect($context->pendingReminderSuggestion)->not->toBeNull();
    expect($context->pendingReminderSuggestion->day)->toBe('tuesday');

    $facts = (new CoachFactsFormatter)->format($context);
    expect($facts)->toContain('RECORDATORIO PROPUESTO PENDIENTE DE CONFIRMACIÓN');
    expect($facts)->toContain('el martes a las 19:00');
});

it('CoachFactsFormatter never mentions a pending reminder when there is none', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '5730000012']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id]);

    $context = coachContextProvider()->provide(executionContextFor($tenant, '5730000012'))->data;
    $facts = (new CoachFactsFormatter)->format($context);

    expect($facts)->not->toContain('RECORDATORIO PROPUESTO PENDIENTE');
});
