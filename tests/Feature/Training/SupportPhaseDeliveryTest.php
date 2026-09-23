<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutExercisePhase;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Facades\Http;

/**
 * Hito R1/R2/R3 — cobertura end-to-end del flujo conversacional de
 * Preparación/Cooldown: entrega progresiva, confirmación explícita
 * determinista (SupportPhaseConfirmationDetector, nunca IA) para avanzar,
 * un mensaje libre NUNCA avanza, R3 se entrega tras el último R1
 * reportado, y la sesión se completa al ENTREGAR (no al confirmar) el
 * último ejercicio de apoyo. Helpers con prefijo "supportPhase" — propios
 * de este archivo, mismo criterio que el resto de la suite para evitar
 * colisión de funciones globales entre archivos de test.
 */
function supportPhaseReadyContact(): Contact
{
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

/**
 * @param  array<int, array{name: string, phase: WorkoutExercisePhase, delivered_at: \Illuminate\Support\Carbon|null, logged: bool}>  $exercises
 * @return array{0: WorkoutSession, 1: array<int, WorkoutExercise>}
 */
function supportPhaseSession(Contact $contact, array $exercises): array
{
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $created = [];

    foreach ($exercises as $i => $spec) {
        $exercise = Exercise::factory()->create(['name' => $spec['name']]);
        $workoutExercise = WorkoutExercise::factory()->create([
            'workout_session_id' => $session->id,
            'exercise_id' => $exercise->id,
            'exercise_snapshot' => $exercise->toSnapshot(),
            'order' => $i + 1,
            'phase' => $spec['phase'],
            'prescribed_sets' => 1,
            'prescribed_reps' => null,
            'prescribed_load' => null,
            'prescribed_duration_seconds' => 90,
            'rest_seconds' => 0,
            'delivered_at' => $spec['delivered_at'],
        ]);

        if ($spec['logged']) {
            ExerciseLog::factory()->create(['workout_exercise_id' => $workoutExercise->id]);
        }

        $created[] = $workoutExercise;
    }

    return [$session, $created];
}

function supportPhaseSendMessage(Contact $contact, string $body): void
{
    $tenant = $contact->tenant;
    $job = new ProcessWhatsAppMessage($tenant, $contact->customer_phone, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

function supportPhaseCoachTurn(?string $trainingReply): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        // 'exercise_question' — uno de los intents en
        // ConversationTurnResolver::TRAINING_REPLY_INTENTS: sin un intent de
        // ese conjunto, "training_reply" nunca se traduce a un SendText.
        'safety_signal_text' => null, 'intents' => ['exercise_question'], 'training_reply' => $trainingReply,
        'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
        'faq_match_id' => null, 'faq_response_text' => null, 'customer_service_needed' => false, 'customer_service_message' => null,
        'conversation_reinforcement_included' => false,
    ])]]]];
}

function supportPhaseReportTurn(array $reports = [], array $intents = [], ?string $trainingReply = null): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'safety_signal_text' => null, 'reports' => $reports, 'session_finished' => false, 'intents' => $intents,
        'training_reply' => $trainingReply, 'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null,
        'reminder_confirmation' => null,
    ])]]]];
}

function supportPhaseOutboundBodies(): \Illuminate\Support\Collection
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body', ''));
}

// ── Confirmación explícita avanza; un mensaje libre nunca avanza ──

it('advances past a delivered Preparation exercise on an explicit confirmation, without calling the AI', function () {
    $contact = supportPhaseReadyContact();
    [, $exercises] = supportPhaseSession($contact, [
        ['name' => 'Movilidad de cadera', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => now()->subMinutes(1), 'logged' => false],
        ['name' => 'Sentadilla búlgara', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null, 'logged' => false],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    supportPhaseSendMessage($contact, 'listo');

    // 100% determinista — ni ExecutionReportService ni CoachService se
    // invocaron para resolver esta confirmación.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));

    $bodies = supportPhaseOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Sentadilla búlgara')))->toBeTrue();
    expect($exercises[1]->fresh()->delivered_at)->not->toBeNull();
});

// ── Hito de confirmación en lenguaje natural (diseño v4) — evidencia E2E real ──

it('E2E real (staging): "Rodillas altas, hice una serie x 90 segundos" advances the Support, creates NO ExerciseLog for it, and never touches ExecutionReportRecorder/ExecutionReportService', function () {
    $contact = supportPhaseReadyContact();
    [, $exercises] = supportPhaseSession($contact, [
        ['name' => 'Rodillas altas', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => now()->subMinutes(1), 'logged' => false],
        ['name' => 'Medio burpee', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null, 'logged' => false],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    supportPhaseSendMessage($contact, 'Rodillas altas, hice una serie x 90 segundos');

    // Determinista — nunca pasó por ExecutionReportService/CoachService
    // (ninguna llamada de IA), confirmando que la rama 4a
    // (SupportPhaseConfirmationDetector) resolvió el turno completo.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));

    // Avanzó: el siguiente WorkoutExercise (Main) fue entregado.
    $bodies = supportPhaseOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Medio burpee')))->toBeTrue();
    expect($exercises[1]->fresh()->delivered_at)->not->toBeNull();

    // Rodillas altas (Preparation) NUNCA fue tratado como Main: sin
    // ExerciseLog, nunca pasó por ExecutionReportRecorder.
    expect(ExerciseLog::where('workout_exercise_id', $exercises[0]->id)->count())->toBe(0);
    expect($exercises[0]->fresh()->requiresExecutionReport())->toBeFalse();
});

it('E2E real (staging): "Hice una pregunta sobre Rodillas altas" does NOT advance the Support — falls through to the normal report/question path instead', function () {
    $contact = supportPhaseReadyContact();
    [, $exercises] = supportPhaseSession($contact, [
        ['name' => 'Rodillas altas', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => now()->subMinutes(1), 'logged' => false],
        ['name' => 'Medio burpee', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null, 'logged' => false],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(supportPhaseReportTurn(
            intents: ['exercise_question'],
            trainingReply: 'Rodillas altas es un ejercicio de calentamiento cardiovascular.',
        )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    supportPhaseSendMessage($contact, 'Hice una pregunta sobre Rodillas altas');

    // NUNCA avanzó: "Medio burpee" no fue entregado.
    $bodies = supportPhaseOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Medio burpee')))->toBeFalse();
    expect($exercises[1]->fresh()->delivered_at)->toBeNull();
    expect(ExerciseLog::where('workout_exercise_id', $exercises[0]->id)->count())->toBe(0);
});

it('never advances past a delivered Preparation exercise on a free-text question — the session still answers, but the next exercise is never sent', function (string $body) {
    $contact = supportPhaseReadyContact();
    // Con un Main todavía sin ExerciseLog en la sesión (el caso típico
    // mientras Preparación está pendiente de confirmación — el Main ni
    // siquiera se ha entregado todavía), el mensaje libre cae al paso 4
    // existente (ExecutionReportService, que también clasifica preguntas
    // vía "intents"/"training_reply" en la MISMA llamada) — nunca al
    // avance determinista de la rama 4a, y nunca a una segunda llamada de
    // IA (CoachService).
    [, $exercises] = supportPhaseSession($contact, [
        ['name' => 'Movilidad de cadera', 'phase' => WorkoutExercisePhase::Preparation, 'delivered_at' => now()->subMinutes(1), 'logged' => false],
        ['name' => 'Sentadilla búlgara', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => null, 'logged' => false],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(supportPhaseReportTurn(
            intents: ['exercise_question'],
            trainingReply: 'Dura unos 30-45 segundos, a tu ritmo.',
        )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    supportPhaseSendMessage($contact, $body);

    $bodies = supportPhaseOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Dura unos 30-45 segundos')))->toBeTrue();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Sentadilla búlgara')))->toBeFalse();
    expect($exercises[1]->fresh()->delivered_at)->toBeNull();
})->with([
    '¿cuánto dura?',
    '¿cómo hago este ejercicio?',
    'me duele la espalda',
    'no puedo hacerlo',
    '¿puedo cambiarlo?',
    'quiero otro ejercicio',
]);

it('never advances past a delivered Cooldown exercise on a free-text question when every Main is already resolved — Coach answers instead', function () {
    $contact = supportPhaseReadyContact();
    // Aquí SÍ está vacío `unreported_exercises` (el único Main ya tiene
    // ExerciseLog) — este es el caso real donde el turno cae al paso 5
    // (CoachService), nunca a ExecutionReportService.
    [, $exercises] = supportPhaseSession($contact, [
        ['name' => 'Sentadilla búlgara', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(10), 'logged' => true],
        ['name' => 'Estiramiento de isquiotibiales', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => now()->subMinutes(1), 'logged' => false],
        ['name' => 'Respiración guiada', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => null, 'logged' => false],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(supportPhaseCoachTurn('Dura unos 30-45 segundos, a tu ritmo.')),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    supportPhaseSendMessage($contact, '¿cuánto dura?');

    $bodies = supportPhaseOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Dura unos 30-45 segundos')))->toBeTrue();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Respiración guiada')))->toBeFalse();
    expect($exercises[2]->fresh()->delivered_at)->toBeNull();
});

// ── R3 se entrega tras el último R1 reportado ──

it('delivers the Cooldown exercise right after the last Main exercise is reported', function () {
    $contact = supportPhaseReadyContact();
    // Dos Cooldown: entregar el PRIMERO no debe completar la sesión — solo
    // el ÚLTIMO ejercicio de la sesión completa al entregarse (ver el
    // siguiente test).
    [$session, $exercises] = supportPhaseSession($contact, [
        ['name' => 'Sentadilla búlgara', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(5), 'logged' => false],
        ['name' => 'Estiramiento de isquiotibiales', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => null, 'logged' => false],
        ['name' => 'Respiración guiada', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => null, 'logged' => false],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(supportPhaseReportTurn(
            reports: [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null, 'sets' => [
                ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
            ], 'rpe_number' => 7, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
        )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    supportPhaseSendMessage($contact, '3 series de 10 con 40kg');

    $bodies = supportPhaseOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Estiramiento de isquiotibiales')))->toBeTrue();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Respiración guiada')))->toBeFalse();
    expect($exercises[1]->fresh()->delivered_at)->not->toBeNull();
    expect($exercises[2]->fresh()->delivered_at)->toBeNull();
    // Todavía queda un segundo Cooldown por entregar — la sesión NO se
    // completa solo por entregar un Cooldown que no es el último.
    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Scheduled);
});

// ── la sesión se completa al ENTREGAR el último ejercicio de apoyo ──

it('completes the session as soon as the last Cooldown exercise is delivered, without waiting for confirmation', function () {
    $contact = supportPhaseReadyContact();
    [$session, $exercises] = supportPhaseSession($contact, [
        ['name' => 'Sentadilla búlgara', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(5), 'logged' => false],
        ['name' => 'Estiramiento de isquiotibiales', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => null, 'logged' => false],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(supportPhaseReportTurn(
            reports: [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null, 'sets' => [
                ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
            ], 'rpe_number' => 7, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
        )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    // Único mensaje: reporta el último Main -> el Cooldown se entrega y,
    // por ser el último ejercicio de la sesión, la completa en el mismo
    // turno (nunca se le pide al usuario que confirme el Cooldown).
    supportPhaseSendMessage($contact, '3 series de 10 con 40kg');

    expect($exercises[1]->fresh()->delivered_at)->not->toBeNull();
    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Completed);
    expect($session->fresh()->completed_at)->not->toBeNull();

    $bodies = supportPhaseOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, '🏁 Sesión completada')))->toBeTrue();
});

it('completes the session when the user explicitly confirms the last delivered Cooldown exercise', function () {
    $contact = supportPhaseReadyContact();
    [$session, $exercises] = supportPhaseSession($contact, [
        ['name' => 'Sentadilla búlgara', 'phase' => WorkoutExercisePhase::Main, 'delivered_at' => now()->subMinutes(10), 'logged' => true],
        ['name' => 'Estiramiento de isquiotibiales', 'phase' => WorkoutExercisePhase::Cooldown, 'delivered_at' => now()->subMinutes(1), 'logged' => false],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    supportPhaseSendMessage($contact, 'ya está');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    expect($session->fresh()->status)->toBe(WorkoutSessionStatus::Completed);

    $bodies = supportPhaseOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, '🏁 Sesión completada')))->toBeTrue();
});
