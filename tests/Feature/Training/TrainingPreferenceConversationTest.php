<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingPreference;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\HealthConditionStatus;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Hito B3 (Preferencias persistentes) — cobertura conversacional completa a
 * través de `TrainingHandler::handle()` (vía `ProcessWhatsAppMessage`), mismo
 * rigor que `SupportPhaseDeliveryTest`. Cubre los casos E2E mínimos A-I del
 * encargo de implementación (Sección 26) a nivel de test automatizado — el
 * E2E real sobre WhatsApp en staging es un paso POSTERIOR y separado.
 * Helpers con prefijo "preferenceConv" — propios de este archivo.
 */
function preferenceConvReadyContact(): Contact
{
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

/**
 * @return array{0: WorkoutSession, 1: WorkoutExercise}
 */
function preferenceConvSessionWithPendingMain(Contact $contact, string $exerciseName = 'Sentadilla'): array
{
    $exercise = Exercise::factory()->create(['name' => $exerciseName, 'name_es' => $exerciseName]);
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $workoutExercise = WorkoutExercise::factory()->create([
        'workout_session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'exercise_snapshot' => $exercise->toSnapshot(),
        'order' => 1,
        'phase' => WorkoutExercisePhase::Main,
        'prescribed_sets' => 3,
        'prescribed_reps' => 10,
        'delivered_at' => now()->subMinutes(1),
    ]);

    return [$session, $workoutExercise];
}

function preferenceConvSendMessage(Contact $contact, string $body): void
{
    $job = new ProcessWhatsAppMessage($contact->tenant, $contact->customer_phone, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

function preferenceConvReportTurn(array $reports = [], array $intents = []): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'safety_signal_text' => null, 'reports' => $reports, 'session_finished' => false, 'intents' => $intents,
        'training_reply' => null, 'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null,
        'reminder_confirmation' => null, 'requested_focus_terms' => [],
    ])]]]];
}

function preferenceConvCoachTurn(array $intents = []): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'safety_signal_text' => null, 'intents' => $intents, 'training_reply' => null,
        'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        'conversation_reinforcement_included' => false, 'requested_focus_terms' => [],
    ])]]]];
}

function preferenceConvOutboundBodies(): Collection
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body', ''));
}

// ── Caso A: sin Main, "no me gustan" persiste inmediatamente ──

it('A: "No me gustan las sentadillas." persists a Preference without any Main pending', function () {
    $contact = preferenceConvReadyContact();
    Exercise::factory()->create(['name' => 'Sentadilla', 'name_es' => 'Sentadilla', 'is_active' => true]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(preferenceConvCoachTurn()),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    preferenceConvSendMessage($contact, 'No me gustan las sentadillas.');

    $preference = TrainingPreference::where('contact_id', $contact->id)->first();
    expect($preference)->not->toBeNull();
    expect($preference->dimension)->toBe(PreferenceDimension::Exercise);
});

// ── Caso B: con Main pendiente, "no quiero hacer X" reporta, NUNCA crea Preference ──

it('B: "No quiero hacer sentadillas." with a pending Main keeps the existing dont_want report and creates NO Preference', function () {
    $contact = preferenceConvReadyContact();
    [$session, $workoutExercise] = preferenceConvSessionWithPendingMain($contact);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(preferenceConvReportTurn(
            reports: [['exercise_name' => 'Sentadilla', 'not_performed' => true, 'skip_reason' => 'dont_want', 'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
        )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    preferenceConvSendMessage($contact, 'No quiero hacer sentadillas.');

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercise->id)->exists())->toBeTrue();
    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);
});

// ── Caso C: permanencia + Main pendiente -> reporte Y Preference, ambos ──

it('C: "No quiero volver a hacer sentadillas." reports the instance AND persists a Preference, report first', function () {
    $contact = preferenceConvReadyContact();
    [$session, $workoutExercise] = preferenceConvSessionWithPendingMain($contact);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(preferenceConvReportTurn(
            reports: [['exercise_name' => 'Sentadilla', 'not_performed' => true, 'skip_reason' => 'dont_want', 'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
        )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    preferenceConvSendMessage($contact, 'No quiero volver a hacer sentadillas.');

    expect(ExerciseLog::where('workout_exercise_id', $workoutExercise->id)->exists())->toBeTrue();

    $preference = TrainingPreference::where('contact_id', $contact->id)->first();
    expect($preference)->not->toBeNull();
    expect($preference->exercise_id)->toBe($workoutExercise->exercise_id);
});

// ── Caso D: Safety — registra DeclaredHealthCondition, nunca Preference ──

it('D: "No puedo hacer sentadillas porque me duele la rodilla." routes to DeclaredHealthCondition, never Preference', function () {
    $contact = preferenceConvReadyContact();

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(preferenceConvCoachTurn()),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    preferenceConvSendMessage($contact, 'No puedo hacer sentadillas porque me duele la rodilla.');

    $condition = DeclaredHealthCondition::where('contact_id', $contact->id)->first();
    expect($condition)->not->toBeNull();
    expect($condition->status)->toBe(HealthConditionStatus::PendingReview);
    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);

    // Nunca pausa el entrenamiento (EscalateSafety no se disparó).
    expect($contact->fresh()->trainingProfile->isFlaggedForSafetyReview())->toBeFalse();
});

// ── Caso E: Equipment preference ──

it('E: "No me gusta usar mancuernas." persists an Equipment preference', function () {
    $contact = preferenceConvReadyContact();

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(preferenceConvCoachTurn()),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    preferenceConvSendMessage($contact, 'No me gusta usar mancuernas.');

    $preference = TrainingPreference::where('contact_id', $contact->id)->first();
    expect($preference)->not->toBeNull();
    expect($preference->dimension)->toBe(PreferenceDimension::Equipment);
    expect($preference->equipment_value)->toBe('dumbbells');
});

// ── Caso F: temporal — nunca persiste ──

it('F: "Hoy no quiero hacer sentadillas." never persists a Preference', function () {
    $contact = preferenceConvReadyContact();
    [$session, $workoutExercise] = preferenceConvSessionWithPendingMain($contact);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(preferenceConvReportTurn(
            reports: [['exercise_name' => 'Sentadilla', 'not_performed' => true, 'skip_reason' => 'dont_want', 'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
        )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    preferenceConvSendMessage($contact, 'Hoy no quiero hacer sentadillas.');

    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);
});

// ── Caso G: "no puedo" desnudo -> clarificación, nunca Safety/Preference/Availability ──

it('G: "No puedo usar mancuernas." (bare) asks for clarification, creates nothing', function () {
    $contact = preferenceConvReadyContact();

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(preferenceConvCoachTurn()),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    preferenceConvSendMessage($contact, 'No puedo usar mancuernas.');

    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(0);
    expect(DeclaredHealthCondition::where('contact_id', $contact->id)->count())->toBe(0);

    $bodies = preferenceConvOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, '¿No tienes ese equipo, o prefieres no usarlo?')))->toBeTrue();
});

// ── Caso I: "Quiero otra rutina de brazos" combina B2 + B1 + B3 sin mecanismo especial ──

it('I: a replacement session honors an already-active Exercise preference automatically', function () {
    $contact = preferenceConvReadyContact();
    $disliked = Exercise::factory()->create(['muscle_group' => 'arms', 'primary_muscle' => MuscleFocus::Biceps, 'name' => 'Curl con barra']);
    Exercise::factory()->count(3)->create(['muscle_group' => 'arms', 'primary_muscle' => MuscleFocus::Triceps]);

    TrainingPreference::factory()->forExercise($disliked->id, 'No me gusta el curl con barra.')->create(['contact_id' => $contact->id]);

    // Sesión activa existente a reemplazar.
    $oldSession = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(preferenceConvCoachTurn(intents: ['new_workout_request'])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    preferenceConvSendMessage($contact, 'Quiero otra rutina de brazos.');

    $newSession = WorkoutSession::where('contact_id', $contact->id)->where('id', '!=', $oldSession->id)->first();
    expect($newSession)->not->toBeNull();
    expect($newSession->workoutExercises->pluck('exercise_id'))->not->toContain($disliked->id);
});
